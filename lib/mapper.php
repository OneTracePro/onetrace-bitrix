<?php

declare(strict_types=1);

namespace Onetrace\Connector;

use Bitrix\Main\UserTable;
use Bitrix\Sale\BasketItem;
use Bitrix\Sale\Order as SaleOrder;
use OneTrace\Commerce\Customer;
use OneTrace\Commerce\LineItem;
use OneTrace\Commerce\Order;

/**
 * Bitrix users and orders in the terms of the common e-commerce contract. Every Bitrix order has a user (the guest
 * checkout registers one), so the user id is the profile id.
 */
final class Mapper
{
    /**
     * A user account; the tracker cookie of the current request, if any.
     */
    public static function customer(int $userId, string $siteId): ?Customer
    {
        $user = UserTable::getRow([
            'select' => ['ID', 'EMAIL', 'NAME', 'LAST_NAME', 'PERSONAL_PHONE', 'PERSONAL_MOBILE', 'PERSONAL_CITY', 'PERSONAL_COUNTRY', 'EXTERNAL_AUTH_ID'],
            'filter' => ['=ID' => $userId],
        ]);

        // Service users (integrations, the anonymous buyer of the "Buy in one click") are not customers.
        if ($user === null || ((string) $user['EXTERNAL_AUTH_ID'] !== '' && (string) $user['EXTERNAL_AUTH_ID'] !== 'socservices')) {
            return null;
        }

        $country = (int) $user['PERSONAL_COUNTRY'] > 0 ? (string) \GetCountryCodeById((int) $user['PERSONAL_COUNTRY']) : null;

        return (new Customer((string) $user['ID'], Connection::anonymousId()))
            ->email((string) $user['EMAIL'])
            ->phone((string) ($user['PERSONAL_MOBILE'] ?: $user['PERSONAL_PHONE']))
            ->name((string) $user['NAME'], (string) $user['LAST_NAME'])
            ->city((string) $user['PERSONAL_CITY'])
            ->country($country)
            ->language(Config::language($siteId));
    }

    /**
     * Customer of an order: the contacts of the order, the account where the order has none (a checkout without
     * these properties), the browser of the checkout saved with the order.
     */
    public static function orderCustomer(SaleOrder $order): Customer
    {
        $properties = $order->getPropertyCollection();
        $userId = (int) $order->getUserId();
        $user = $userId > 0 ? UserTable::getRow(['select' => ['EMAIL', 'NAME', 'LAST_NAME', 'PERSONAL_PHONE', 'PERSONAL_MOBILE', 'PERSONAL_CITY'], 'filter' => ['=ID' => $userId]]) : null;
        $name = trim((string) $properties->getPayerName()?->getValue());
        $parts = $name !== '' ? (preg_split('/\s+/u', $name, 2) ?: []) : [(string) ($user['NAME'] ?? ''), (string) ($user['LAST_NAME'] ?? '')];
        $city = $properties->getItemByOrderPropertyCode('CITY')?->getValue();

        return (new Customer($userId > 0 ? (string) $userId : null, self::orderVisitor((int) $order->getId()) ?? Connection::anonymousId()))
            ->email((string) ($properties->getUserEmail()?->getValue() ?: ($user['EMAIL'] ?? '')))
            ->phone((string) ($properties->getPhone()?->getValue() ?: ($user['PERSONAL_MOBILE'] ?? '') ?: ($user['PERSONAL_PHONE'] ?? '')))
            ->name($parts[0] ?? null, $parts[1] ?? null)
            ->city(is_string($city) && $city !== '' ? $city : (string) ($user['PERSONAL_CITY'] ?? ''))
            ->language(Config::language((string) $order->getSiteId()));
    }

    public static function order(SaleOrder $order): Order
    {
        $created = $order->getDateInsert();
        $placedAt = (new \DateTimeImmutable('@' . ($created ? $created->getTimestamp() : time())))->setTimezone(new \DateTimeZone('UTC'));
        $payment = $order->getPaymentCollection()->current();
        $shipment = null;

        foreach ($order->getShipmentCollection() as $item) {
            if (!$item->isSystem()) {
                $shipment = $item;

                break;
            }
        }

        return (new Order(
            (string) ($order->getField('ACCOUNT_NUMBER') ?: $order->getId()),
            (float) $order->getPrice(),
            (string) $order->getCurrency(),
            $placedAt,
            self::orderCustomer($order),
            self::lines($order->getBasket()?->getOrderableItems() ?? [])
        ))->with([
            'subtotal' => (float) ($order->getBasket()?->getPrice() ?? 0),
            'shipping' => (float) $order->getDeliveryPrice(),
            'tax' => (float) $order->getTaxPrice(),
            'discount' => abs((float) $order->getDiscountPrice()),
            'coupon' => self::coupon($order),
            'payment_method' => $payment ? (string) $payment->getPaymentSystemName() : '',
            'shipping_method' => $shipment ? (string) $shipment->getDeliveryName() : '',
        ]);
    }

    /**
     * A basket or order line: the product with the offer as the variant.
     */
    public static function line(BasketItem $item, ?int $quantity = null): LineItem
    {
        [$productId, $offerId] = Catalog::productOf((int) $item->getProductId());
        $quantity ??= (int) $item->getQuantity();

        return new LineItem(
            $productId,
            (string) $item->getField('NAME'),
            (float) $item->getPrice(),
            max(1, $quantity),
            $offerId,
            Catalog::categoryId($productId)
        );
    }

    public static function rememberVisitor(int $orderId, string $anonymousId): void
    {
        if (OrderTable::getByPrimary($orderId)->fetch()) {
            OrderTable::update($orderId, ['ANONYMOUS_ID' => $anonymousId]);
        } else {
            OrderTable::add(['ORDER_ID' => $orderId, 'ANONYMOUS_ID' => $anonymousId]);
        }
    }

    /**
     * @param iterable<BasketItem> $items
     *
     * @return list<LineItem>
     */
    private static function lines(iterable $items): array
    {
        $lines = [];

        foreach ($items as $item) {
            $lines[] = self::line($item);
        }

        return $lines;
    }

    private static function orderVisitor(int $orderId): ?string
    {
        $row = $orderId > 0 ? OrderTable::getByPrimary($orderId)->fetch() : false;

        return is_array($row) && (string) $row['ANONYMOUS_ID'] !== '' ? (string) $row['ANONYMOUS_ID'] : null;
    }

    private static function coupon(SaleOrder $order): string
    {
        if ($order->getId() <= 0) {
            return '';
        }

        $row = \Bitrix\Sale\Internals\OrderCouponsTable::getRow(['select' => ['COUPON'], 'filter' => ['=ORDER_ID' => $order->getId()]]);

        return (string) ($row['COUPON'] ?? '');
    }
}
