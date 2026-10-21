<?php

declare(strict_types=1);

namespace Onetrace\Connector\StoreMcp;

use Bitrix\Catalog\ProductTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Mail\Event as MailEvent;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sale\Delivery\Services\EmptyDeliveryService;
use Bitrix\Sale\Delivery\Services\Manager as Deliveries;
use Bitrix\Sale\Order;
use Bitrix\Sale\OrderStatus;
use Bitrix\Sale\Shipment;
use OneTrace\StoreMcp\Context;
use OneTrace\StoreMcp\ToolError;
use Onetrace\Connector\Catalog;
use Onetrace\Connector\Config;
use Onetrace\Connector\Queue;
use Onetrace\Connector\StoreEmails;

Loc::loadMessages(__FILE__);

/**
 * Tools of the store MCP server (contract sections 4.1–4.3). Bitrix has no returns and no delivery changes from
 * the customer: those requests go to the manager comment of the order and to the shop by the mail event
 * ONETRACE_STORE_MCP_REQUEST. The bot cancels only orders with nothing paid and nothing shipped, as the personal
 * section of the shop allows.
 */
final class Tools
{
    public const REQUEST_EVENT = 'ONETRACE_STORE_MCP_REQUEST';

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public static function customer(array $args, Context $ctx): array
    {
        $userIds = Customers::userIds((string) $ctx->customerId);
        $user = $userIds !== [] ? \Bitrix\Main\UserTable::getRow(['select' => ['EMAIL', 'NAME', 'PERSONAL_PHONE', 'PERSONAL_MOBILE'], 'filter' => ['=ID' => $userIds[0]]]) : null;

        return self::filled([
            'id' => $ctx->customerId,
            'email' => (string) ($user['EMAIL'] ?? ''),
            'phone' => (string) (($user['PERSONAL_MOBILE'] ?? '') ?: ($user['PERSONAL_PHONE'] ?? '')),
            'first_name' => (string) ($user['NAME'] ?? ''),
        ]);
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public static function findOrders(array $args, Context $ctx): array
    {
        $limit = max(1, min(20, is_numeric($args['limit'] ?? null) ? (int) $args['limit'] : 5));
        $status = is_string($args['status'] ?? null) && $args['status'] !== '' ? $args['status'] : null;
        $orders = [];

        // The contract status comes from the flags and shipments of the order: filtered after reading.
        foreach (Customers::orders((string) $ctx->customerId, $status === null ? $limit : 50) as $order) {
            if ($status !== null && self::status($order) !== $status) {
                continue;
            }

            $orders[] = [
                'order' => (string) $order->getField('ACCOUNT_NUMBER'),
                'created_at' => self::date($order->getDateInsert()),
                'status' => self::status($order),
                'status_label' => self::statusLabel($order),
                'total' => self::money((float) $order->getPrice(), (string) $order->getCurrency()),
                'items_count' => count($order->getBasket()->getBasketItems()),
            ];

            if (count($orders) >= $limit) {
                break;
            }
        }

        return ['orders' => $orders];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public static function getOrder(array $args, Context $ctx): array
    {
        $order = Customers::order($args, $ctx);
        $currency = (string) $order->getCurrency();
        $items = [];
        $payments = [];
        $tracking = [];
        $delivery = '';

        foreach ($order->getBasket()->getBasketItems() as $item) {
            $items[] = ['name' => (string) $item->getField('NAME'), 'sku' => (string) $item->getField('PRODUCT_XML_ID'), 'quantity' => (float) $item->getQuantity(), 'price' => self::money((float) $item->getPrice(), $currency)];
        }

        foreach ($order->getPaymentCollection() as $payment) {
            $payments[] = (string) $payment->getPaymentSystemName();
        }

        foreach (self::shipments($order) as $shipment) {
            $delivery = $delivery !== '' ? $delivery : (string) $shipment->getDeliveryName();
            $tracking = $tracking !== [] ? $tracking : StoreEmails::tracking($shipment);
        }

        return [
            'order' => (string) $order->getField('ACCOUNT_NUMBER'),
            'created_at' => self::date($order->getDateInsert()),
            'status' => self::status($order),
            'status_label' => self::statusLabel($order),
            'items' => $items,
            'total' => self::money((float) $order->getPrice(), $currency),
            'payment' => ['method' => implode(', ', array_filter($payments)), 'status' => $order->isPaid() ? 'paid' : 'unpaid'],
            'shipping' => self::filled([
                'method' => $delivery,
                'address' => self::address($order),
                'carrier' => $tracking['carrier'] ?? null,
                'tracking_number' => $tracking['tracking_number'] ?? null,
                'tracking_url' => $tracking['tracking_url'] ?? null,
            ]),
            'can_cancel' => self::cancellable($order),
            'can_return' => self::received($order),
        ];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public static function tracking(array $args, Context $ctx): array
    {
        $order = Customers::order($args, $ctx);
        $tracking = [];
        $events = [];

        foreach (self::shipments($order) as $shipment) {
            if ($shipment->isShipped()) {
                $tracking = $tracking !== [] ? $tracking : StoreEmails::tracking($shipment);
                $shipped = $shipment->getField('DATE_DEDUCTED');
                $events[] = ['at' => $shipped instanceof DateTime ? self::date($shipped) : null, 'status' => 'shipped', 'location' => null];
            }
        }

        if ($events === []) {
            throw new ToolError(ToolError::NOT_FOUND, (string) Loc::getMessage('ONETRACE_STORE_MCP_NOT_SHIPPED'));
        }

        return self::filled($tracking + ['status' => self::status($order), 'events' => $events]);
    }

    /**
     * Products of the catalog of the site by SKU (XML_ID), id or a part of the name; offers are the variants.
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public static function availability(array $args, Context $ctx): array
    {
        Loader::includeModule('iblock');
        Loader::includeModule('catalog');
        $siteId = (string) SITE_ID;
        $iblocks = Catalog::iblocks($siteId);
        $filter = match (true) {
            is_string($args['sku'] ?? null) && $args['sku'] !== '' => ['=XML_ID' => $args['sku']],
            is_scalar($args['product_id'] ?? null) && (int) $args['product_id'] > 0 => ['ID' => (int) $args['product_id']],
            is_string($args['query'] ?? null) && trim($args['query']) !== '' => ['%NAME' => trim($args['query'])],
            default => null,
        };
        $ids = [];

        if ($filter !== null && $iblocks !== []) {
            $elements = \CIBlockElement::GetList(['SORT' => 'ASC', 'ID' => 'ASC'], $filter + ['IBLOCK_ID' => $iblocks, 'ACTIVE' => 'Y', 'ACTIVE_DATE' => 'Y', 'CHECK_PERMISSIONS' => 'N'], false, ['nTopCount' => 10], ['ID']);

            while ($element = $elements->Fetch()) {
                $ids[] = (int) $element['ID'];
            }
        }

        $products = [];

        foreach ($ids !== [] ? Catalog::items($siteId, $ids)[0] : [] as $item) {
            $products[] = self::filled([
                'product_id' => (string) $item['id'],
                'name' => (string) $item['name'],
                'url' => $item['url'] ?? null,
                'price' => isset($item['price']) ? self::money((float) $item['price'], (string) $item['currency']) : null,
                'in_stock' => (bool) $item['available'],
                'quantity' => $item['stock'] ?? null,
                'variants' => self::variants((int) $item['id']),
            ]);
        }

        return ['products' => $products];
    }

    /**
     * Active delivery services: the price and the period of the configurable ones; the services that calculate by
     * the address have none.
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public static function delivery(array $args, Context $ctx): array
    {
        $methods = [];
        $empty = (int) EmptyDeliveryService::getEmptyDeliveryServiceId();

        foreach (Deliveries::getActiveList() as $id => $service) {
            $class = ltrim((string) ($service['CLASS_NAME'] ?? ''), '\\');

            if ((int) $id === $empty || str_contains($class, 'Group')) {
                continue;
            }

            $main = (array) ($service['CONFIG']['MAIN'] ?? []);
            $period = (array) ($main['PERIOD'] ?? []);
            $days = ($period['TYPE'] ?? 'D') === 'D';
            $methods[] = self::filled([
                'name' => (string) $service['NAME'],
                'price' => $class === \Bitrix\Sale\Delivery\Services\Configurable::class && is_numeric($main['PRICE'] ?? null) ? self::money((float) $main['PRICE'], (string) ($main['CURRENCY'] ?? $service['CURRENCY'] ?? '')) : null,
                'min_days' => $days && is_numeric($period['FROM'] ?? null) && (int) $period['FROM'] > 0 ? (int) $period['FROM'] : null,
                'max_days' => $days && is_numeric($period['TO'] ?? null) && (int) $period['TO'] > 0 ? (int) $period['TO'] : null,
            ]);
        }

        return ['methods' => $methods];
    }

    /**
     * The return policy of the module settings of the site.
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public static function returnPolicy(array $args, Context $ctx): array
    {
        $siteId = (string) SITE_ID;
        $text = Config::get('store_mcp_return_text', $siteId);
        $url = Config::get('store_mcp_return_url', $siteId);

        if ($text === '' && $url === '') {
            throw new ToolError(ToolError::NOT_FOUND, (string) Loc::getMessage('ONETRACE_STORE_MCP_NO_RETURN_POLICY'));
        }

        return self::filled(['text' => mb_substr($text, 0, 3000), 'url' => $url !== '' ? StoreEmails::absolute($url, \Onetrace\Connector\Connection::siteUrl($siteId)) : null]);
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public static function cancel(array $args, Context $ctx): array
    {
        $order = Customers::order($args, $ctx);
        $done = ['order' => (string) $order->getField('ACCOUNT_NUMBER'), 'status' => 'cancelled', 'message' => (string) Loc::getMessage('ONETRACE_STORE_MCP_CANCELLED')];

        if ($order->isCanceled()) {
            return $done;
        }

        if (!self::cancellable($order)) {
            throw new ToolError(ToolError::NOT_ALLOWED, (string) Loc::getMessage('ONETRACE_STORE_MCP_CANNOT_CANCEL'));
        }

        $reason = trim(Loc::getMessage('ONETRACE_STORE_MCP_CANCEL_REASON') . ' ' . (is_string($args['reason'] ?? null) ? $args['reason'] : ''));
        $result = $order->setField('CANCELED', 'Y');

        if ($result->isSuccess()) {
            $order->setField('REASON_CANCELED', $reason);
            $result = $order->save();
        }

        if (!$result->isSuccess()) {
            Queue::log('store MCP cancel ' . $order->getId() . ': ' . implode('; ', $result->getErrorMessages()));

            throw new ToolError(ToolError::NOT_ALLOWED, (string) Loc::getMessage('ONETRACE_STORE_MCP_CANNOT_CANCEL'));
        }

        return $done;
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public static function createReturn(array $args, Context $ctx): array
    {
        $order = Customers::order($args, $ctx);

        if (!self::received($order)) {
            throw new ToolError(ToolError::NOT_ALLOWED, (string) Loc::getMessage('ONETRACE_STORE_MCP_RETURN_LATER'));
        }

        $items = [];

        foreach (is_array($args['items'] ?? null) ? $args['items'] : [] as $item) {
            if (is_array($item) && is_scalar($item['sku'] ?? null)) {
                $items[] = (string) $item['sku'] . ' × ' . (is_numeric($item['quantity'] ?? null) ? (int) $item['quantity'] : 1);
            }
        }

        $text = (string) Loc::getMessage('ONETRACE_STORE_MCP_RETURN_REQUEST', ['#ITEMS#' => implode(', ', $items), '#REASON#' => is_string($args['reason'] ?? null) ? $args['reason'] : '']);

        return self::request($order, $ctx, $text, 'return');
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public static function changeDelivery(array $args, Context $ctx): array
    {
        $order = Customers::order($args, $ctx);

        if ($order->isCanceled() || $order->isShipped() || self::shipped($order)) {
            throw new ToolError(ToolError::NOT_ALLOWED, (string) Loc::getMessage('ONETRACE_STORE_MCP_ALREADY_SHIPPED'));
        }

        $parts = array_filter([
            is_string($args['address'] ?? null) ? Loc::getMessage('ONETRACE_STORE_MCP_ADDRESS', ['#VALUE#' => $args['address']]) : null,
            is_string($args['date'] ?? null) ? Loc::getMessage('ONETRACE_STORE_MCP_DATE', ['#VALUE#' => $args['date']]) : null,
            is_string($args['time_window'] ?? null) ? Loc::getMessage('ONETRACE_STORE_MCP_TIME', ['#VALUE#' => $args['time_window']]) : null,
        ]);

        return self::request($order, $ctx, (string) Loc::getMessage('ONETRACE_STORE_MCP_DELIVERY_REQUEST', ['#CHANGES#' => implode(', ', $parts)]), 'delivery');
    }

    /**
     * A request for the shop: the manager comment of the order and the mail event to the shop address; repeated with
     * the same idempotency key — the same answer.
     *
     * @return array<string, mixed>
     */
    private static function request(Order $order, Context $ctx, string $text, string $kind): array
    {
        $store = new CacheCodeStore();
        $key = 'request|' . (string) $ctx->idempotencyKey;
        $done = $ctx->idempotencyKey !== null ? $store->get($key) : null;

        if ($done !== null) {
            return $done;
        }

        $comments = trim((string) $order->getField('COMMENTS'));
        $order->setField('COMMENTS', ltrim($comments . "\n" . $text));
        $saved = $order->save();

        if (!$saved->isSuccess()) {
            Queue::log('store MCP request ' . $order->getId() . ': ' . implode('; ', $saved->getErrorMessages()));

            throw new ToolError(ToolError::UNAVAILABLE, (string) Loc::getMessage('ONETRACE_STORE_MCP_UNAVAILABLE'));
        }

        $number = (string) $order->getField('ACCOUNT_NUMBER');
        // The shop address of the order emails, otherwise the sender address of the site.
        $shop = trim((string) \Bitrix\Main\Config\Option::get('sale', 'order_email', '')) ?: trim((string) \Bitrix\Main\Config\Option::get('main', 'email_from', ''));
        MailEvent::sendImmediate(['EVENT_NAME' => self::REQUEST_EVENT, 'LID' => (string) $order->getSiteId(), 'C_FIELDS' => [
            'EMAIL_TO' => $shop,
            'ORDER_ID' => $number,
            'ORDER_REAL_ID' => (string) $order->getId(),
            'KIND' => (string) Loc::getMessage($kind === 'return' ? 'ONETRACE_STORE_MCP_KIND_RETURN' : 'ONETRACE_STORE_MCP_KIND_DELIVERY'),
            'REQUEST' => $text,
        ]]);

        $result = $kind === 'return'
            ? ['return_id' => 'R' . $number . '-' . substr(sha1((string) ($ctx->idempotencyKey ?? $text)), 0, 6), 'status' => 'requested', 'instructions' => (string) Loc::getMessage('ONETRACE_STORE_MCP_RETURN_NEXT')]
            : ['order' => $number, 'message' => (string) Loc::getMessage('ONETRACE_STORE_MCP_REQUEST_PASSED')];

        if ($ctx->idempotencyKey !== null) {
            $store->put($key, $result, 86400);
        }

        return $result;
    }

    /**
     * Contract status: cancelled, the "Delivered" statuses of the settings, "F" (completed), shipped, paid
     * (processing) or waiting (pending).
     */
    private static function status(Order $order): string
    {
        $statusId = (string) $order->getField('STATUS_ID');

        return match (true) {
            $order->isCanceled() => 'cancelled',
            in_array($statusId, Config::deliveredStatuses((string) $order->getSiteId()), true) => 'delivered',
            $statusId === 'F' => 'completed',
            $order->isShipped() || self::shipped($order) => 'shipped',
            $order->isPaid() => 'processing',
            default => 'pending',
        };
    }

    private static function statusLabel(Order $order): string
    {
        $names = OrderStatus::getAllStatusesNames(Config::language((string) $order->getSiteId()));

        return (string) ($names[(string) $order->getField('STATUS_ID')] ?? '');
    }

    /**
     * As the personal section of the shop: nothing paid and nothing shipped.
     */
    private static function cancellable(Order $order): bool
    {
        return !$order->isCanceled() && !$order->isPaid() && (float) $order->getSumPaid() <= 0.0 && !$order->isShipped() && !self::shipped($order)
            && !in_array(self::status($order), ['completed', 'delivered'], true);
    }

    private static function received(Order $order): bool
    {
        return !$order->isCanceled() && in_array(self::status($order), ['completed', 'delivered'], true);
    }

    private static function shipped(Order $order): bool
    {
        foreach (self::shipments($order) as $shipment) {
            if ($shipment->isShipped()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<Shipment>
     */
    private static function shipments(Order $order): array
    {
        $shipments = [];

        foreach ($order->getShipmentCollection() as $shipment) {
            if ($shipment instanceof Shipment && !$shipment->isSystem()) {
                $shipments[] = $shipment;
            }
        }

        return $shipments;
    }

    /** City and street of the order: the properties of the checkout, the location when there is no city. */
    private static function address(Order $order): ?string
    {
        $properties = $order->getPropertyCollection();
        $city = trim((string) $properties->getItemByOrderPropertyCode('CITY')?->getValue());
        $location = (string) $properties->getDeliveryLocation()?->getValue();

        if ($city === '' && $location !== '') {
            try {
                $city = (string) \Bitrix\Sale\Location\Admin\LocationHelper::getLocationStringByCode($location, ['INVERSE' => false, 'DELIMITER' => ', ', 'LANGUAGE_ID' => Config::language((string) $order->getSiteId())]);
            } catch (\Throwable) {
                $city = '';
            }
        }

        $address = trim($city . ', ' . trim((string) $properties->getAddress()?->getValue()), ', ');

        return $address !== '' ? $address : null;
    }

    /**
     * Offers of a product: the name of the offer and its availability.
     *
     * @return list<array{attributes: array<string, string>, sku: string, in_stock: bool}>
     */
    private static function variants(int $productId): array
    {
        $offers = \CCatalogSku::getOffersList([$productId], 0, ['ACTIVE' => 'Y'], ['ID', 'NAME', 'XML_ID']);
        $rows = (array) ($offers[$productId] ?? []);

        if ($rows === []) {
            return [];
        }

        $available = array_column(ProductTable::getList(['select' => ['ID', 'AVAILABLE'], 'filter' => ['@ID' => array_map('intval', array_keys($rows))]])->fetchAll(), 'AVAILABLE', 'ID');
        $variants = [];

        foreach ($rows as $id => $offer) {
            $variants[] = ['attributes' => ['name' => html_entity_decode((string) $offer['NAME'])], 'sku' => (string) $offer['XML_ID'], 'in_stock' => ($available[$id] ?? 'N') === ProductTable::STATUS_YES];
        }

        return $variants;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function filled(array $data): array
    {
        return array_filter($data, static fn ($value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * @return array{amount: float, currency: string}
     */
    private static function money(float $amount, string $currency): array
    {
        return ['amount' => round($amount, 2), 'currency' => $currency];
    }

    private static function date(?DateTime $date): ?string
    {
        return $date !== null ? date(DATE_ATOM, $date->getTimestamp()) : null;
    }
}
