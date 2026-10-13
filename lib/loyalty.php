<?php

declare(strict_types=1);

namespace Onetrace\Connector;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sale\Basket;
use Bitrix\Sale\Fuser;
use Bitrix\Sale\Order;
use Bitrix\Sale\Payment;
use Bitrix\Sale\PaySystem\Manager as PaySystems;
use OneTrace\Exception\ApiException;
use OneTrace\Identity;

\Bitrix\Main\Localization\Loc::loadMessages(__FILE__);

/**
 * Bonus points of the OneTrace loyalty program at checkout (sale.order.ajax): the offer for the basket, a hold of
 * the chosen points for 30 minutes in the session, the points applied to the order in one of two ways chosen in the
 * settings — a separate payment "Bonus points" (prices and catalog discounts stay as they are, the money part of the
 * order goes to the platform as the amount) or a discount spread over the basket lines — and the confirmation with
 * the order number. Only signed-in customers redeem points: the platform finds them by user_id, like the order
 * events. Cancelled and refunded orders return the points on the platform side.
 */
final class Loyalty
{
    public const MODE_PAYMENT = 'payment';

    public const MODE_DISCOUNT = 'discount';

    private const SESSION_KEY = 'ONETRACE_LOYALTY';

    private const OFFER_TTL = 60;

    /** Off, a payment or a discount for the site. */
    public static function mode(string $siteId): ?string
    {
        $mode = Config::get('loyalty', $siteId, 'N');

        return in_array($mode, [self::MODE_PAYMENT, self::MODE_DISCOUNT], true) && Config::server($siteId) ? $mode : null;
    }

    /**
     * The signed-in customer of the request; null — a guest.
     *
     * @return array{type: string, value: string}|null
     */
    public static function profile(): ?array
    {
        global $USER;

        $userId = is_object($USER) && $USER->IsAuthorized() ? (int) $USER->GetID() : 0;

        return $userId > 0 ? Identity::userId((string) $userId) : null;
    }

    /** Amount the points may pay: the goods of the basket with their discounts, without delivery. */
    public static function basketAmount(string $siteId): float
    {
        $basket = Basket::loadItemsForFUser(Fuser::getId(), $siteId);

        return round((float) $basket->getOrderableItems()->getPrice(), 2);
    }

    /**
     * @return array<string, mixed>|null member, balance, max_points, max_discount, earn_points, point_value, currency, tier
     */
    public static function offer(string $siteId, float $amount): ?array
    {
        $profile = self::profile();

        if ($profile === null || $amount <= 0 || self::mode($siteId) === null) {
            return null;
        }

        $key = 'offer:' . $profile['value'] . ':' . $amount;
        $cached = $_SESSION[self::SESSION_KEY][$siteId]['offers'][$key] ?? null;

        if (is_array($cached) && ($cached['at'] ?? 0) > time() - self::OFFER_TTL) {
            return $cached['offer'];
        }

        try {
            $offer = Connection::client($siteId)->loyalty()->calculate($profile, $amount);
        } catch (\Throwable $error) {
            Queue::log('loyalty offer: ' . $error->getMessage());

            return null;
        }

        $_SESSION[self::SESSION_KEY][$siteId]['offers'] = [$key => ['offer' => $offer, 'at' => time()]];

        return $offer;
    }

    /**
     * The hold of this basket: id, points, discount, amount (basket amount at the hold), user, held_until.
     *
     * @return array{id: string, points: int, discount: float, amount: float, user: string, held_until: int}|null
     */
    public static function hold(string $siteId): ?array
    {
        $hold = $_SESSION[self::SESSION_KEY][$siteId]['hold'] ?? null;
        $profile = self::profile();

        if (!is_array($hold) || $profile === null || ($hold['user'] ?? null) !== $profile['value'] || !isset($hold['id'])) {
            return null;
        }

        return [
            'id' => (string) $hold['id'],
            'points' => (int) ($hold['points'] ?? 0),
            'discount' => (float) ($hold['discount'] ?? 0),
            'amount' => (float) ($hold['amount'] ?? 0),
            'user' => (string) $hold['user'],
            'held_until' => (int) ($hold['held_until'] ?? 0),
        ];
    }

    /**
     * Holds the points for the basket (a new hold of the same basket replaces the previous one on the platform).
     *
     * @throws \Throwable the platform refused: more than the balance or the allowed share of the basket
     */
    public static function apply(string $siteId, int $points, ?float $amount = null): void
    {
        $profile = self::profile();

        if ($profile === null || $points <= 0) {
            throw new \InvalidArgumentException((string) \Bitrix\Main\Localization\Loc::getMessage('ONETRACE_LOYALTY_SIGN_IN'));
        }

        $amount ??= self::basketAmount($siteId);
        $redemption = Connection::client($siteId)->loyalty()->hold($profile, $points, 'bx:' . Fuser::getId(), $amount);
        $_SESSION[self::SESSION_KEY][$siteId] = [
            'hold' => [
                'id' => (string) $redemption['id'],
                'points' => (int) $redemption['points'],
                'discount' => (float) $redemption['discount'],
                'amount' => $amount,
                'user' => $profile['value'],
                'held_until' => (int) strtotime((string) $redemption['held_until']),
            ],
        ];
    }

    /** Removes the points from the basket and returns them to the balance. */
    public static function remove(string $siteId): void
    {
        $hold = self::hold($siteId);
        unset($_SESSION[self::SESSION_KEY][$siteId]);

        if ($hold !== null) {
            try {
                Connection::client($siteId)->loyalty()->cancel($hold['id']);
            } catch (\Throwable $error) {
                // The hold expires by itself in 30 minutes.
            }
        }
    }

    /**
     * sale.order.ajax built the order (every refresh and before saving): a changed basket or a hold about to expire
     * is held again within the allowed share, then the points go into the order as the payment or the discount.
     */
    public static function applyToOrder(Order $order): void
    {
        $siteId = (string) $order->getSiteId();
        $mode = self::mode($siteId);
        $hold = self::hold($siteId);

        if ($mode === null || $hold === null) {
            return;
        }

        $amount = round((float) $order->getBasket()->getOrderableItems()->getPrice(), 2);

        if (abs($amount - $hold['amount']) >= 0.01 || $hold['held_until'] - time() < 120) {
            $offer = self::offer($siteId, $amount);
            $perPoint = $hold['points'] > 0 ? $hold['discount'] / $hold['points'] : 1.0;
            $allowed = $offer !== null && $perPoint > 0 ? (int) floor((float) ($offer['max_discount'] ?? 0) / $perPoint + 1e-9) : 0;

            try {
                self::apply($siteId, min($hold['points'], $allowed), $amount);
            } catch (\Throwable $error) {
                self::remove($siteId);

                return;
            }

            $hold = self::hold($siteId);

            if ($hold === null) {
                return;
            }
        }

        $discount = min($hold['discount'], $amount);

        if ($discount <= 0) {
            return;
        }

        $mode === self::MODE_PAYMENT ? self::addPayment($order, $discount) : self::discountBasket($order, $discount);
    }

    /**
     * The order is saved: the redemption of the session is confirmed with the order number; if the platform does
     * not answer, the agent retries.
     */
    public static function placed(Order $order): void
    {
        $siteId = (string) $order->getSiteId();
        $hold = self::hold($siteId);

        if ($hold === null || self::mode($siteId) === null || (int) $order->getUserId() !== (int) $hold['user']) {
            return;
        }

        unset($_SESSION[self::SESSION_KEY][$siteId]);
        LoyaltyTable::add([
            'ORDER_ID' => (int) $order->getId(), 'SITE_ID' => $siteId, 'REDEMPTION' => $hold['id'], 'POINTS' => $hold['points'],
            'DISCOUNT' => $hold['discount'], 'CREATED_AT' => new DateTime(),
        ]);
        self::confirm((int) $order->getId());
    }

    /**
     * Confirms the redemption of the order with its number; false — the platform did not answer.
     */
    public static function confirm(int $orderId): bool
    {
        $row = LoyaltyTable::getByPrimary($orderId)->fetch();
        $order = Order::load($orderId);

        if (!$row || $row['CONFIRMED_AT'] !== null || $order === null) {
            return true;
        }

        try {
            Connection::client((string) $row['SITE_ID'])->loyalty()->confirm((string) $row['REDEMPTION'], (string) ($order->getField('ACCOUNT_NUMBER') ?: $order->getId()));
        } catch (\Throwable $error) {
            Queue::log('loyalty confirmation of order ' . $orderId . ': ' . $error->getMessage());

            // 4xx (the hold expired or was confirmed by another order) — retrying will not help.
            if (!$error instanceof ApiException || $error->getCode() >= 500 || $error->getCode() === 429) {
                return false;
            }
        }

        LoyaltyTable::update($orderId, ['CONFIRMED_AT' => new DateTime()]);

        return true;
    }

    /** Agent: confirmations of recent orders that failed at checkout (the hold lives 30 minutes). */
    public static function retry(): void
    {
        $rows = LoyaltyTable::getList([
            'select' => ['ORDER_ID'],
            'filter' => ['=CONFIRMED_AT' => null, '>=CREATED_AT' => DateTime::createFromTimestamp(time() - 2400)],
            'limit' => 100,
        ]);

        while ($row = $rows->fetch()) {
            self::confirm((int) $row['ORDER_ID']);
        }
    }

    /** The points paid by the order: the sum of its payment "Bonus points". */
    public static function paidWithPoints(Order $order): float
    {
        $paySystemId = (int) Config::get('loyalty_paysystem');
        $sum = 0.0;

        /** @var Payment $payment */
        foreach ($order->getPaymentCollection() as $payment) {
            if ($paySystemId > 0 && (int) $payment->getPaymentSystemId() === $paySystemId) {
                $sum += (float) $payment->getSum();
            }
        }

        return $sum;
    }

    /**
     * The pay system "Bonus points" of the payment mode: created once, inactive — customers do not choose it, the
     * module adds the payment itself.
     */
    public static function paySystemId(): int
    {
        $id = (int) Config::get('loyalty_paysystem');

        if ($id > 0 && PaySystems::getById($id)) {
            return $id;
        }

        $result = PaySystems::add([
            'NAME' => (string) (\Bitrix\Main\Localization\Loc::getMessage('ONETRACE_LOYALTY_PAYSYSTEM') ?: 'Bonus points'),
            'PSA_NAME' => (string) (\Bitrix\Main\Localization\Loc::getMessage('ONETRACE_LOYALTY_PAYSYSTEM') ?: 'Bonus points'),
            'ACTION_FILE' => 'cash',
            'ACTIVE' => 'N',
            'ENTITY_REGISTRY_TYPE' => 'ORDER',
            'NEW_WINDOW' => 'N',
            'HAVE_PREPAY' => 'N',
            'HAVE_RESULT' => 'N',
            'HAVE_ACTION' => 'N',
            'HAVE_PAYMENT' => 'N',
            'HAVE_RESULT_RECEIVE' => 'N',
            'IS_CASH' => 'N',
            'CAN_PRINT_CHECK' => 'N',
            'SORT' => 1000,
            'DESCRIPTION' => 'OneTrace loyalty program',
        ]);

        if (!$result->isSuccess()) {
            throw new \RuntimeException(implode('; ', $result->getErrorMessages()));
        }

        $id = (int) $result->getId();
        PaySystems::update($id, ['PARAMS' => serialize(['BX_PAY_SYSTEM_ID' => $id])]);
        Config::set('loyalty_paysystem', (string) $id);

        return $id;
    }

    /**
     * Payment mode: the payment "Bonus points" (paid) with the discount, the payment of the customer is smaller by it.
     */
    private static function addPayment(Order $order, float $discount): void
    {
        $paySystemId = self::paySystemId();
        $payments = $order->getPaymentCollection();
        $points = null;
        $other = null;

        /** @var Payment $payment */
        foreach ($payments as $payment) {
            if ((int) $payment->getPaymentSystemId() === $paySystemId) {
                $points = $payment;
            } elseif ($other === null && !$payment->isPaid()) {
                $other = $payment;
            }
        }

        $discount = min($discount, (float) $order->getPrice());

        if ($points === null) {
            $points = $payments->createItem(PaySystems::getObjectById($paySystemId));
        }

        $points->setField('SUM', $discount);
        $points->setField('PAID', 'Y');

        if ($other !== null) {
            $other->setField('SUM', max(0.0, (float) $order->getPrice() - $discount));
        }
    }

    /**
     * Discount mode: the discount spread over the basket lines in proportion to their sums (custom prices, so the
     * catalog does not recalculate them), the remainder of rounding — on the last line.
     */
    private static function discountBasket(Order $order, float $discount): void
    {
        $items = $order->getBasket()->getOrderableItems();
        $total = (float) $items->getPrice();
        $left = round($discount, 2);
        $count = count($items);
        $index = 0;

        foreach ($items as $item) {
            $index++;
            $quantity = (float) $item->getQuantity();
            $line = (float) $item->getFinalPrice();

            if ($quantity <= 0 || $total <= 0) {
                continue;
            }

            $share = $index === $count ? $left : round($discount * $line / $total, 2);
            $share = min($share, $line, $left);
            $left = round($left - $share, 2);
            $price = round((float) $item->getPrice() - $share / $quantity, 4);
            $item->setFields([
                'CUSTOM_PRICE' => 'Y',
                'PRICE' => max(0.0, $price),
                'DISCOUNT_PRICE' => round((float) $item->getDiscountPrice() + $share / $quantity, 4),
            ]);
        }
    }

    /** The connection of the database for the table of the module (created on install, here — for upgrades). */
    public static function install(): void
    {
        $connection = Application::getConnection();

        if (!$connection->isTableExists(LoyaltyTable::getTableName())) {
            $sql = (string) file_get_contents(dirname(__DIR__) . '/install/db/mysql/install.sql');

            if (preg_match('/CREATE TABLE IF NOT EXISTS b_onetrace_loyalty \(.*?\);/s', $sql, $match) === 1) {
                $connection->queryExecute($match[0]);
            }
        }
    }
}
