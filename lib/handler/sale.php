<?php

declare(strict_types=1);

namespace Onetrace\Connector\Handler;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Event;
use Bitrix\Sale\BasketItem;
use Bitrix\Sale\Delivery\Services\EmptyDeliveryService;
use Bitrix\Sale\Delivery\Tracking\Manager as Tracking;
use Bitrix\Sale\DeliveryStatus;
use Bitrix\Sale\Order;
use Bitrix\Sale\Payment;
use Bitrix\Sale\Shipment;
use Bitrix\Sale\ShipmentItem;
use OneTrace\Commerce\Customer;
use OneTrace\Commerce\Messages;
use Onetrace\Connector\Config;
use Onetrace\Connector\Connection;
use Onetrace\Connector\Mapper;
use Onetrace\Connector\Queue;

/**
 * Orders and the cart from the server (module "sale"): placed, paid, shipped, delivered, cancelled and refunded orders
 * with deterministic message ids; cart changes, the deferred lines as the wishlist and the checkout for visitors with the
 * tracker cookie or signed in.
 * The store must never fail because of OneTrace: errors go to the event log.
 */
final class Sale
{
    /**
     * How long a shipment handed over without a tracking number waits for one (seconds): a number entered meanwhile
     * goes with order_shipped, after that the event goes without it.
     */
    public const TRACKING_WAIT = 12 * 3600;

    /**
     * OnSaleOrderSaved: a new order — identify with the order data and order_completed; a paid order that stopped
     * being paid with a returned payment — order_refunded (Bitrix fires no payment event for a return); the order
     * came to a status of the setting "Order statuses “Delivered”" ("Выполнен" by default) — order_delivered.
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

            if (array_key_exists('STATUS_ID', $before)) {
                self::delivered($order, (string) $before['STATUS_ID']);
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

        // An order placed already completed (the admin, an exchange).
        self::delivered($order, '');
    }

    /**
     * OnSaleShipmentEntitySaved: a shipment (not the system one) handed over to delivery — deducted ("Отгружено") or
     * in the shipped status ("Отгружен", or the status the "sale" module sets on deduction) — gives order_shipped once
     * per shipment, with the delivery service, the tracking number, its link from the tracking of the delivery
     * service and the products of the shipment. Without a tracking number the event waits TRACKING_WAIT in the queue:
     * a number entered meanwhile goes with it; a number entered later or a changed one gives no second event.
     */
    public static function onShipmentSaved(Event $event): void
    {
        $shipment = $event->getParameter('ENTITY');

        if (!$shipment instanceof Shipment || $shipment->isSystem() || !self::shipped($shipment->getFields()->getValues())) {
            return;
        }

        $order = $shipment->getOrder();

        if (!$order instanceof Order || !Config::enabled('orders', (string) $order->getSiteId())) {
            return;
        }

        $before = $event->getParameter('IS_NEW') ? ['DEDUCTED' => 'N', 'STATUS_ID' => '', 'TRACKING_NUMBER' => ''] : (array) $event->getParameter('VALUES');
        $tracking = trim((string) $shipment->getField('TRACKING_NUMBER'));

        if (!self::shipped($before + $shipment->getFields()->getValues())) {
            self::shipment($order, $shipment, $tracking === '' ? self::TRACKING_WAIT : 0);
        } elseif ($tracking !== '' && array_key_exists('TRACKING_NUMBER', $before) && trim((string) $before['TRACKING_NUMBER']) === '') {
            self::shipment($order, $shipment, null);
        }
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
     * Shipment fields mean handed over to delivery: deducted or in a shipped status.
     *
     * @param array<string, mixed> $fields
     */
    private static function shipped(array $fields): bool
    {
        $statuses = [DeliveryStatus::getFinalStatus(), (string) Option::get('sale', 'shipment_status_on_shipped', '')];

        return ($fields['DEDUCTED'] ?? 'N') === 'Y' || in_array((string) ($fields['STATUS_ID'] ?? ''), array_filter($statuses), true);
    }

    /**
     * order_shipped of a shipment: queued with a delay (seconds) or, with null, only in place of the one waiting for
     * the tracking number — none waits when it is sent already.
     */
    private static function shipment(Order $order, Shipment $shipment, ?int $delay): void
    {
        self::safely('shipped order ' . $order->getId(), static function () use ($order, $shipment, $delay): void {
            $model = Mapper::order($order);

            if ($model->customer->ids() === []) {
                return;
            }

            $siteId = (string) $order->getSiteId();
            $deliveryId = (int) $shipment->getDeliveryId();
            $tracking = trim((string) $shipment->getField('TRACKING_NUMBER'));
            // "Без доставки" is not a carrier the customer knows.
            $carrier = $deliveryId !== (int) EmptyDeliveryService::getEmptyDeliveryServiceId() ? trim((string) $shipment->getDeliveryName()) : '';
            $url = $tracking !== '' && $deliveryId > 0 ? self::trackingUrl($deliveryId, $tracking) : '';
            $deducted = $shipment->getField('DATE_DEDUCTED');
            $at = $shipment->isShipped() && $deducted instanceof \Bitrix\Main\Type\DateTime
                ? (new \DateTimeImmutable('@' . $deducted->getTimestamp()))->setTimezone(new \DateTimeZone('UTC'))
                : self::now();
            $lines = [];

            /** @var ShipmentItem $item */
            foreach ($shipment->getShipmentItemCollection() as $item) {
                $basketItem = $item->getBasketItem();

                if ($basketItem instanceof BasketItem && $item->getQuantity() > 0) {
                    $lines[] = Mapper::line($basketItem, (int) $item->getQuantity());
                }
            }

            $message = Connection::messages($siteId)->orderShipped($model, $carrier ?: null, $tracking ?: null, $url ?: null, null, $at, (string) $shipment->getId(), $lines);
            $key = 'shipment' . $shipment->getId();

            $delay === null ? Queue::replace($siteId, $key, $message) : Queue::pending($siteId, $key, $message, $delay);
        });
    }

    /**
     * The tracking link of the delivery service (its tracking class, such as the Russian Post one); empty — none.
     */
    private static function trackingUrl(int $deliveryId, string $tracking): string
    {
        try {
            return trim((string) Tracking::getInstance()->getTrackingUrl($deliveryId, $tracking));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * order_delivered when the order came to a delivered status from another one.
     */
    private static function delivered(Order $order, string $before): void
    {
        $siteId = (string) $order->getSiteId();
        $statuses = Config::deliveredStatuses($siteId);

        if (!in_array((string) $order->getField('STATUS_ID'), $statuses, true) || in_array($before, $statuses, true)) {
            return;
        }

        self::safely('delivered order ' . $order->getId(), static function () use ($order, $siteId): void {
            $model = Mapper::order($order);

            if ($model->customer->ids() !== []) {
                Queue::event($siteId, Connection::messages($siteId)->orderDelivered($model, self::now()));
            }
        });
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

    /**
     * OnSaleBasketItemSaved: a cart line added or its quantity changed (not the lines of placed orders). The deferred
     * lines ("Отложенные", DELAY = Y) are the wishlist of Bitrix: deferring a line takes it out of the cart into the
     * wishlist, moving it back — the other way round; a new deferred line goes only to the wishlist.
     */
    public static function onBasketItemSaved(Event $event): void
    {
        $item = $event->getParameter('ENTITY');

        if (!$item instanceof BasketItem || (int) $item->getField('ORDER_ID') > 0) {
            return;
        }

        $before = (array) $event->getParameter('VALUES');
        $isNew = (bool) $event->getParameter('IS_NEW');
        $deferred = $item->isDelay();
        // A changed DELAY is among the original values; null there — the line was saved without it (not deferred).
        $wasDeferred = $isNew ? false : (array_key_exists('DELAY', $before) ? $before['DELAY'] === 'Y' : $deferred);
        $old = $isNew ? 0 : (int) ($before['QUANTITY'] ?? $item->getQuantity());

        if (!$isNew && $deferred !== $wasDeferred) {
            if ($deferred) {
                self::cart($item, 'remove_from_cart', $old, $item->canBuy());
                self::cart($item, 'add_to_wishlist');
            } else {
                self::cart($item, 'remove_from_wishlist');
                self::cart($item, 'add_to_cart', (int) $item->getQuantity(), $item->canBuy());
            }

            return;
        }

        if ($deferred) {
            if ($isNew) {
                self::cart($item, 'add_to_wishlist');
            }

            return;
        }

        $delta = (int) $item->getQuantity() - $old;

        if ($delta !== 0) {
            self::cart($item, $delta > 0 ? 'add_to_cart' : 'remove_from_cart', abs($delta), $item->canBuy());
        }
    }

    /** OnSaleBasketItemEntityDeleted: a cart line removed; a deferred one leaves the wishlist. */
    public static function onBasketItemDeleted(Event $event): void
    {
        $item = $event->getParameter('ENTITY');

        if ($item instanceof BasketItem && (int) $item->getField('ORDER_ID') <= 0) {
            $item->isDelay() ? self::cart($item, 'remove_from_wishlist') : self::cart($item, 'remove_from_cart', (int) $item->getQuantity());
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

    /**
     * A product event of a cart line: add_to_cart, remove_from_cart, add_to_wishlist or remove_from_wishlist (the
     * wishlist events carry no quantity). Cart events only for lines that can be bought; the wishlist keeps products
     * out of stock too — they wait for a "back in stock" journey.
     */
    private static function cart(BasketItem $item, string $event, int $quantity = 1, bool $canBuy = true): void
    {
        $siteId = (string) ($item->getField('LID') ?: (defined('SITE_ID') ? SITE_ID : ''));
        $customer = $canBuy ? self::visitor($siteId) : null;

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
