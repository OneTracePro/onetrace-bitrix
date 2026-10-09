<?php

declare(strict_types=1);

namespace Onetrace\Connector\Handler;

use Bitrix\Main\Application;
use Bitrix\Main\Event;
use Bitrix\Sale\BasketItem;
use Bitrix\Sale\Order;
use Bitrix\Sale\Payment;
use OneTrace\Commerce\Customer;
use OneTrace\Commerce\Messages;
use Onetrace\Connector\Config;
use Onetrace\Connector\Connection;
use Onetrace\Connector\Mapper;
use Onetrace\Connector\Queue;

/**
 * Orders and the cart from the server (module "sale"): placed, paid, cancelled and refunded orders with
 * deterministic message ids; cart changes and the checkout for visitors with the tracker cookie or signed in.
 * The store must never fail because of OneTrace: errors go to the event log.
 */
final class Sale
{
    /**
     * OnSaleOrderSaved: a new order — identify with the order data and order_completed; a paid order that stopped
     * being paid with a returned payment — order_refunded (Bitrix fires no payment event for a return).
     */
    public static function onOrderSaved(Event $event): void
    {
        $order = $event->getParameter('ENTITY');

        if (!$order instanceof Order || !Config::enabled('orders', (string) $order->getSiteId())) {
            return;
        }

        $before = (array) $event->getParameter('VALUES');

        if (!$event->getParameter('IS_NEW')) {
            if (($before['PAYED'] ?? null) === 'Y' && !$order->isPaid()) {
                self::refunded($order);
            }

            return;
        }

        self::safely('order ' . $order->getId(), static function () use ($order): void {
            $siteId = (string) $order->getSiteId();
            $anonymousId = Connection::anonymousId();

            if ($anonymousId !== null) {
                Mapper::rememberVisitor((int) $order->getId(), $anonymousId);
            }

            $model = Mapper::order($order);

            if ($model->customer->ids() === []) {
                return;
            }

            $messages = Connection::messages($siteId);
            Queue::event($siteId, $messages->identify($model->customer, self::consents($order), $model->placedAt));
            Queue::event($siteId, $messages->orderCompleted($model));
        });
    }

    /** OnSaleOrderPaid: the order became paid (several payments of one order give one order_paid). */
    public static function onOrderPaid(Event $event): void
    {
        $order = $event->getParameter('ENTITY');

        if ($order instanceof Order && $order->isPaid() && Config::enabled('orders', (string) $order->getSiteId())) {
            self::safely('paid order ' . $order->getId(), static function () use ($order): void {
                $model = Mapper::order($order);

                if ($model->customer->ids() !== []) {
                    Queue::event((string) $order->getSiteId(), Connection::messages((string) $order->getSiteId())->orderPaid($model, self::now()));
                }
            });
        }
    }

    /** OnSaleOrderCanceled: the order was cancelled (not the undoing of a cancellation). */
    public static function onOrderCanceled(Event $event): void
    {
        $order = $event->getParameter('ENTITY');

        if ($order instanceof Order && $order->isCanceled() && Config::enabled('orders', (string) $order->getSiteId())) {
            self::safely('cancelled order ' . $order->getId(), static function () use ($order): void {
                $model = Mapper::order($order);

                if ($model->customer->ids() !== []) {
                    Queue::event((string) $order->getSiteId(), Connection::messages((string) $order->getSiteId())->orderCancelled($model, self::now()));
                }
            });
        }
    }

    /**
     * order_refunded for each returned payment of the order (to the inner account or by the payment system), with its
     * sum; the payment id keeps one event per payment.
     */
    private static function refunded(Order $order): void
    {
        self::safely('refunded order ' . $order->getId(), static function () use ($order): void {
            $model = null;

            /** @var Payment $payment */
            foreach ($order->getPaymentCollection() as $payment) {
                if (!in_array($payment->getField('IS_RETURN'), ['Y', 'P'], true)) {
                    continue;
                }

                $model ??= Mapper::order($order);

                if ($model->customer->ids() !== []) {
                    Queue::event((string) $order->getSiteId(), Connection::messages((string) $order->getSiteId())->orderRefunded($model, (float) $payment->getSum(), (string) $payment->getId(), self::now()));
                }
            }
        });
    }

    /** OnSaleBasketItemSaved: a cart line added or its quantity changed (not the lines of placed orders). */
    public static function onBasketItemSaved(Event $event): void
    {
        $item = $event->getParameter('ENTITY');

        if (!$item instanceof BasketItem || (int) $item->getField('ORDER_ID') > 0 || !$item->canBuy()) {
            return;
        }

        $before = (array) $event->getParameter('VALUES');
        $old = $event->getParameter('IS_NEW') ? 0 : (int) ($before['QUANTITY'] ?? $item->getQuantity());
        $delta = (int) $item->getQuantity() - $old;

        if ($delta !== 0) {
            self::cart($item, $delta > 0 ? 'add_to_cart' : 'remove_from_cart', abs($delta));
        }
    }

    /** OnSaleBasketItemEntityDeleted: a cart line removed. */
    public static function onBasketItemDeleted(Event $event): void
    {
        $item = $event->getParameter('ENTITY');

        if ($item instanceof BasketItem && (int) $item->getField('ORDER_ID') <= 0) {
            self::cart($item, 'remove_from_cart', (int) $item->getQuantity());
        }
    }

    /**
     * OnSaleComponentOrderUserResult (component sale.order.ajax): the checkout is open — checkout_started once per
     * cart content and session.
     *
     * @param array<string, mixed> $result
     */
    public static function onCheckout(&$result, $request = null, &$params = null): void
    {
        $siteId = (string) (defined('SITE_ID') ? SITE_ID : '');
        $customer = self::visitor($siteId);

        if ($customer === null) {
            return;
        }

        self::safely('checkout', static function () use ($siteId, $customer): void {
            $basket = \Bitrix\Sale\Basket::loadItemsForFUser(\Bitrix\Sale\Fuser::getId(), $siteId);
            $lines = [];

            foreach ($basket->getOrderableItems() as $item) {
                $lines[] = Mapper::line($item);
            }

            if ($lines === []) {
                return;
            }

            $session = Application::getInstance()->getSession();
            $hash = md5((string) json_encode(array_map(static fn ($line): array => $line->toArray(), $lines)));

            if ($session->get('onetrace_checkout') === $hash) {
                return;
            }

            $session->set('onetrace_checkout', $hash);
            Queue::event($siteId, Connection::messages($siteId)->checkoutStarted($customer, $lines, (float) $basket->getPrice(), (string) \Bitrix\Currency\CurrencyManager::getBaseCurrency()));
        });
    }

    /**
     * The visitor of a storefront request: the signed-in user or the tracker cookie; null — cannot be linked.
     */
    public static function visitor(string $siteId): ?Customer
    {
        global $USER;

        if ($siteId === '' || !Config::enabled('cart', $siteId) || !Config::server($siteId)) {
            return null;
        }

        $userId = is_object($USER) && $USER->IsAuthorized() ? (string) $USER->GetID() : null;
        $anonymousId = Connection::anonymousId();

        return $userId !== null || $anonymousId !== null ? new Customer($userId, $anonymousId) : null;
    }

    private static function cart(BasketItem $item, string $event, int $quantity): void
    {
        $siteId = (string) ($item->getField('LID') ?: (defined('SITE_ID') ? SITE_ID : ''));
        $customer = self::visitor($siteId);

        if ($customer !== null) {
            self::safely($event, static function () use ($siteId, $customer, $item, $event, $quantity): void {
                Queue::event($siteId, Connection::messages($siteId)->product($event, $customer, Mapper::line($item, $quantity), (string) $item->getCurrency()));
            });
        }
    }

    /**
     * Newsletter consent of the checkout: the order property from the settings (Y/N).
     *
     * @return list<array{channel: string, status: string, topic?: string}>
     */
    private static function consents(Order $order): array
    {
        $siteId = (string) $order->getSiteId();
        $code = Config::consentProperty($siteId);

        if ($code === '' || !Config::enabled('newsletter', $siteId)) {
            return [];
        }

        return $order->getPropertyCollection()->getItemByOrderPropertyCode($code)?->getValue() === 'Y' ? [Messages::subscribed('email', Config::consentTopic($siteId))] : [];
    }

    private static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    private static function safely(string $what, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $error) {
            Queue::log($what . ': ' . $error->getMessage());
        }
    }
}
