<?php

declare(strict_types=1);

namespace Onetrace\Connector\StoreMcp;

use Bitrix\Catalog\Product\Basket as CatalogBasket;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SiteTable;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use Bitrix\Sale\Basket;
use Bitrix\Sale\BasketItem;
use Bitrix\Sale\Delivery\CalculationResult;
use Bitrix\Sale\Delivery\Services\EmptyDeliveryService;
use Bitrix\Sale\Delivery\Services\Manager as Deliveries;
use Bitrix\Sale\Internals\PersonTypeTable;
use Bitrix\Sale\Location\ExternalTable;
use Bitrix\Sale\Location\LocationTable;
use Bitrix\Sale\Order;
use Bitrix\Sale\Payment;
use Bitrix\Sale\PaySystem\Manager as PaySystems;
use OneTrace\StoreMcp\Context;
use OneTrace\StoreMcp\ToolError;
use Onetrace\Connector\CartLink;
use Onetrace\Connector\Config;
use Onetrace\Connector\Connection;
use Onetrace\Connector\Queue;

Loc::loadMessages(__FILE__);

/**
 * Orders from AI shopping assistants (store MCP contract v2): store_quote_order prices the products as the checkout
 * of the store does (a draft order of the sale module: catalog prices and discounts, delivery services allowed for the
 * location of the address and their prices, payment systems allowed for the delivery), store_create_order places it
 * for the confirmed customer or a guest (an account is made as the standard checkout does) and answers with the link
 * to the payment page of the site.
 *
 * Product ids are those of the catalog the module uploads (as in the cart links): a product element, or an offer —
 * that offer; a product with offers, an unknown, inactive or unavailable product is "unavailable".
 *
 * A repeated store_create_order with the same idempotency key gives the same order: the key is kept with the order in
 * b_onetrace_assistant_order (the server keeps the answer in the cache too). The order gets a manager comment with the
 * assistant, and its order events go to OneTrace with properties.ai_assistant.
 */
final class Orders
{
    /** The prices of the last quote of the products are checked by store_create_order this long. */
    private const QUOTE_TTL = 86400;

    /** A placing that has not finished in this time (a broken request) no longer holds its idempotency key. */
    private const PLACING_TIMEOUT = 600;

    public const ASSISTANTS = ['chatgpt' => 'ChatGPT', 'claude' => 'Claude', 'perplexity' => 'Perplexity', 'gemini' => 'Gemini', 'copilot' => 'Copilot'];

    /** The assistant of the order being saved by create(): "" — the OneTrace chat bot, null — none is being placed. */
    private static ?string $placing = null;

    private static ?bool $installed = null;

    /**
     * @param array{items: list<array{product_id: string, quantity: int}>, shipping: array<string, string>, shipping_method: string|null} $args
     *
     * @return array<string, mixed>
     */
    public static function quote(array $args, Context $ctx): array
    {
        $siteId = (string) SITE_ID;
        $userIds = $ctx->customerId !== null ? Customers::userIds($ctx->customerId) : [];
        $draft = self::draft($siteId, $userIds[0] ?? null, $args['items'], $args['shipping'], $args['shipping_method'], null, false);
        $prices = [];

        foreach ($draft['lines'] as $id => $line) {
            $prices[$id] = round((float) $line->getPrice(), 2);
        }

        $shipping = array_column($draft['methods'], 'price', 'id');

        if ($draft['method'] !== null) {
            $shipping[$draft['method']] = round((float) $draft['order']->getDeliveryPrice(), 2);
        }

        (new CacheCodeStore())->put(self::quoteKey($siteId, $args['items']), ['prices' => $prices, 'shipping' => $shipping], self::QUOTE_TTL);

        return self::summary($draft);
    }

    /**
     * @param array{items: list<array{product_id: string, quantity: int}>, shipping: array<string, string>, shipping_method: string, payment_method: string|null, comment: string|null} $args
     *
     * @return array<string, mixed>
     */
    public static function create(array $args, Context $ctx): array
    {
        $siteId = (string) SITE_ID;
        self::install();
        $key = sha1(($ctx->customerId !== null ? 'customer:' . $ctx->customerId : 'guest:' . $ctx->guestContact) . '|' . $ctx->idempotencyKey);
        $existing = self::existing($key);

        if ($existing !== null) {
            return self::placed($existing);
        }

        try {
            AssistantOrderTable::add([
                'IDEMPOTENCY_KEY' => $key, 'ORDER_ID' => 0, 'SITE_ID' => $siteId, 'SOURCE' => $ctx->source,
                'ASSISTANT' => (string) $ctx->assistant, 'CREATED_AT' => new DateTime(),
            ]);
        } catch (\Bitrix\Main\DB\SqlQueryException) {
            // Another request with this key has just started placing the order.
            throw new ToolError(ToolError::UNAVAILABLE, (string) Loc::getMessage('ONETRACE_STORE_MCP_ORDER_IN_PROGRESS'));
        }

        try {
            $order = self::place($siteId, $args, $ctx);
        } catch (\Throwable $error) {
            // Nothing placed: a repeat with this key may try again.
            AssistantOrderTable::delete($key);

            throw $error;
        }

        AssistantOrderTable::update($key, ['ORDER_ID' => (int) $order->getId()]);

        return self::placed($order);
    }

    /**
     * The OneTrace AI assistant of an order (chatgpt, claude, …): the one being placed now, otherwise the one kept with
     * the order; null — the order did not come from an assistant.
     */
    public static function assistantOf(Order $order): ?string
    {
        if (self::$placing !== null) {
            return self::$placing !== '' ? self::$placing : null;
        }

        if ((int) $order->getId() <= 0 || !self::installed()) {
            return null;
        }

        $row = AssistantOrderTable::getRow(['select' => ['ASSISTANT'], 'filter' => ['=ORDER_ID' => (int) $order->getId()]]);

        return is_array($row) && (string) $row['ASSISTANT'] !== '' ? (string) $row['ASSISTANT'] : null;
    }

    /**
     * An order event with the assistant of the order: properties.ai_assistant.
     *
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    public static function tag(array $message, Order $order): array
    {
        $assistant = self::assistantOf($order);

        if ($assistant !== null && is_array($message['properties'] ?? null)) {
            $message['properties']['ai_assistant'] = $assistant;
        }

        return $message;
    }

    /**
     * The payment page of the site: the setting "Order payment page", the standard /personal/order/payment/ of the
     * site folder otherwise.
     */
    public static function paymentPage(string $siteId): string
    {
        $page = Config::get('payment_page', $siteId);

        if (preg_match('#^/(?![/\\\\])[^\s]*$#', $page) === 1) {
            return $page;
        }

        $site = SiteTable::getRow(['select' => ['DIR'], 'filter' => ['=LID' => $siteId]]);

        return rtrim((string) ($site['DIR'] ?? '/'), '/') . '/personal/order/payment/';
    }

    /**
     * The link to pay a payment of the order on the site (the component sale.order.payment of the page): with the
     * access hash of the order when the store lets guests view orders (sale settings), so a customer who is not
     * signed in can pay; otherwise the customer signs in first.
     */
    public static function paymentUrl(Order $order, Payment $payment): string
    {
        $siteId = (string) $order->getSiteId();
        $page = self::paymentPage($siteId);
        $query = 'ORDER_ID=' . urlencode(urlencode((string) $order->getField('ACCOUNT_NUMBER'))) . '&PAYMENT_ID=' . urlencode((string) $payment->getField('ACCOUNT_NUMBER'));

        if (\Bitrix\Sale\Helpers\Order::isAllowGuestView($order)) {
            $query .= '&HASH=' . urlencode((string) $order->getHash());
        }

        $origin = (string) preg_replace('#^(https?://[^/]+).*$#i', '$1', Connection::siteUrl($siteId));

        return $origin . $page . (str_contains($page, '?') ? '&' : '?') . $query;
    }

    /** The table of the orders from assistants (the module installed before 1.13.0 gets it here). */
    public static function install(): void
    {
        if (self::installed()) {
            return;
        }

        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/install/db/mysql/install.sql');

        if (preg_match('/CREATE TABLE IF NOT EXISTS b_onetrace_assistant_order \(.*?\);/s', $sql, $match) === 1) {
            Application::getConnection()->queryExecute($match[0]);
            self::$installed = true;
        }
    }

    private static function installed(): bool
    {
        return self::$installed ??= Application::getConnection()->isTableExists(AssistantOrderTable::getTableName());
    }

    /**
     * The order placed earlier with this idempotency key; a placing that is still running answers "unavailable", a
     * broken one older than PLACING_TIMEOUT is forgotten.
     */
    private static function existing(string $key): ?Order
    {
        $row = AssistantOrderTable::getByPrimary($key)->fetch();

        if (!is_array($row)) {
            return null;
        }

        $order = (int) $row['ORDER_ID'] > 0 ? Order::load((int) $row['ORDER_ID']) : null;

        if ($order instanceof Order) {
            return $order;
        }

        $created = $row['CREATED_AT'];

        if ((int) $row['ORDER_ID'] > 0 || ($created instanceof DateTime && $created->getTimestamp() < time() - self::PLACING_TIMEOUT)) {
            AssistantOrderTable::delete($key);

            return null;
        }

        throw new ToolError(ToolError::UNAVAILABLE, (string) Loc::getMessage('ONETRACE_STORE_MCP_ORDER_IN_PROGRESS'));
    }

    /**
     * Checks the products, prices and the address again and saves the order.
     *
     * @param array{items: list<array{product_id: string, quantity: int}>, shipping: array<string, string>, shipping_method: string, payment_method: string|null, comment: string|null} $args
     */
    private static function place(string $siteId, array $args, Context $ctx): Order
    {
        $userId = self::account($ctx);
        $draft = self::draft($siteId, $userId, $args['items'], $args['shipping'], $args['shipping_method'], $args['payment_method'], true);
        $order = $draft['order'];

        if ($draft['unavailable'] !== []) {
            throw new ToolError(ToolError::INVALID_INPUT, (string) Loc::getMessage('ONETRACE_STORE_MCP_ORDER_UNAVAILABLE', ['#PRODUCTS#' => implode(', ', $draft['unavailable'])]));
        }

        self::checkPrices($siteId, $args['items'], $draft);
        self::fill($order, $args['shipping'], $ctx, $userId);

        // A guest without an account gets one only for an order that passed the checks.
        if ($userId === null) {
            $order->setFieldNoDemand('USER_ID', Customers::register($siteId, $ctx->guestEmail(), $ctx->guestPhone(), $args['shipping']));
        }

        if ($args['comment'] !== null) {
            $order->setField('USER_DESCRIPTION', $args['comment']);
        }

        $order->setField('COMMENTS', self::source($ctx));
        self::$placing = (string) $ctx->assistant;

        try {
            $saved = $order->save();
        } finally {
            self::$placing = null;
        }

        if (!$saved->isSuccess()) {
            Queue::log('store MCP order of site ' . $siteId . ': ' . implode('; ', $saved->getErrorMessages()));

            throw new ToolError(ToolError::UNAVAILABLE, (string) Loc::getMessage('ONETRACE_STORE_MCP_ORDER_FAILED'));
        }

        return $order;
    }

    /**
     * The answer of store_create_order for an order: its number, status, total, how it is paid and the payment link.
     *
     * @return array<string, mixed>
     */
    private static function placed(Order $order): array
    {
        $payment = null;

        foreach ($order->getPaymentCollection() as $item) {
            if ($item instanceof Payment && !$item->isInner()) {
                $payment ??= $item;
            }
        }

        $paySystem = $payment !== null ? PaySystems::getById((int) $payment->getPaymentSystemId()) : false;
        $status = match (true) {
            $order->isPaid() => 'paid',
            is_array($paySystem) && self::onDelivery($paySystem) => 'on_delivery',
            default => 'pending',
        };

        return [
            'order' => (string) $order->getField('ACCOUNT_NUMBER'),
            'status' => Tools::status($order),
            'total' => Tools::money((float) $order->getPrice(), (string) $order->getCurrency()),
            'payment_status' => $status,
            'payment_url' => $status === 'pending' && $payment !== null ? self::paymentUrl($order, $payment) : null,
        ];
    }

    /**
     * A draft order of the products as the checkout of the store makes it: the basket with catalog prices and
     * discounts, the delivery services allowed for the address with their prices, the chosen (or the first) one, the
     * payment systems allowed then and the chosen (or the first) one. Strict — for the order: an unknown delivery
     * service or payment system is an error, not the first one.
     *
     * @param list<array{product_id: string, quantity: int}> $items
     * @param array<string, string> $shipping
     *
     * @return array{order: Order, lines: array<string, BasketItem>, unavailable: list<string>, methods: list<array{id: string, name: string, price: float, min_days: int|null, max_days: int|null}>, method: string|null, payments: list<array{id: string, name: string}>}
     */
    private static function draft(string $siteId, ?int $userId, array $items, array $shipping, ?string $method, ?string $paymentMethod, bool $strict): array
    {
        Loader::includeModule('iblock');
        Loader::includeModule('catalog');
        Loader::includeModule('sale');
        $order = Order::create($siteId, $userId);
        $order->isStartField();
        $personType = self::personType($siteId);

        if ($personType > 0) {
            $order->setPersonTypeId($personType);
        }

        $basket = Basket::create($siteId);
        $wanted = [];
        $unavailable = [];

        foreach ($items as $item) {
            $id = $item['product_id'];
            $elementId = preg_match('/^[1-9]\d{0,17}$/', $id) === 1 ? CartLink::element($siteId, (int) $id) : null;
            // A product with offers needs the choice of an offer, as in the cart links.
            $elementId = $elementId !== null && empty(\CCatalogSku::getExistOffers([$elementId])[$elementId]) ? $elementId : null;
            $added = $elementId !== null && !in_array($elementId, array_column($wanted, 0), true)
                ? CatalogBasket::addProductToBasket($basket, ['PRODUCT_ID' => $elementId, 'QUANTITY' => $item['quantity'], 'CURRENCY' => $order->getCurrency(), 'LID' => $siteId], ['SITE_ID' => $siteId, 'USER_ID' => (int) $userId])
                : null;

            if ($added === null || !$added->isSuccess()) {
                $unavailable[] = $id;

                continue;
            }

            $wanted[$id] = [$elementId, $item['quantity']];
        }

        $order->setBasket($basket);
        $lines = [];

        // The catalog refreshes the lines with the order: what cannot be bought in this quantity is unavailable.
        foreach ($wanted as $id => [$elementId, $quantity]) {
            $line = null;

            foreach ($basket as $basketItem) {
                $line = (int) $basketItem->getProductId() === $elementId ? $basketItem : $line;
            }

            if (!$line instanceof BasketItem || !$line->canBuy() || (float) $line->getQuantity() < $quantity) {
                $unavailable[] = (string) $id;
                $line?->delete();

                continue;
            }

            $lines[(string) $id] = $line;
        }

        $properties = $order->getPropertyCollection();
        $location = self::location($shipping);

        if ($location !== null) {
            $properties->getDeliveryLocation()?->setValue($location);
        }

        if (isset($shipping['postcode'])) {
            $properties->getDeliveryLocationZip()?->setValue($shipping['postcode']);
        }

        if (isset($shipping['city'])) {
            $properties->getItemByOrderPropertyCode('CITY')?->setValue($shipping['city']);
        }

        $shipments = $order->getShipmentCollection();
        $shipment = $shipments->createItem();

        foreach ($basket as $basketItem) {
            $shipment->getShipmentItemCollection()->createItem($basketItem)->setQuantity($basketItem->getQuantity());
        }

        $shipment->setField('CURRENCY', $order->getCurrency());
        $empty = (int) EmptyDeliveryService::getEmptyDeliveryServiceId();
        $methods = [];
        // Bitrix lets services restricted by location pass while the location is not chosen; an address the
        // locations of the store do not know is not in their areas.
        $outside = $location === null && $shipping !== [] ? self::locationRestricted() : [];

        foreach ($lines !== [] ? Deliveries::getRestrictedObjectsList($shipment) : [] as $deliveryId => $service) {
            if ((int) $deliveryId === $empty || $service::canHasChildren() || in_array((int) $deliveryId, $outside, true)) {
                continue;
            }

            $shipment->setField('DELIVERY_ID', (int) $deliveryId);
            $calculated = $service->calculate($shipment);

            // A service that cannot price this address (an error of its calculator) is not offered, as at checkout.
            if (!$calculated->isSuccess()) {
                continue;
            }

            $days = (string) $calculated->getPeriodType() === CalculationResult::PERIOD_TYPE_DAY;
            $methods[] = [
                'id' => (string) $deliveryId,
                'name' => (string) ($service->isProfile() ? $service->getNameWithParent() : $service->getName()),
                'price' => round((float) $calculated->getPrice(), 2),
                'min_days' => $days && (int) $calculated->getPeriodFrom() > 0 ? (int) $calculated->getPeriodFrom() : null,
                'max_days' => $days && (int) $calculated->getPeriodTo() > 0 ? (int) $calculated->getPeriodTo() : null,
            ];
        }

        $chosen = null;

        foreach ($methods as $candidate) {
            $chosen = $candidate['id'] === $method ? $candidate : $chosen;
        }

        if ($chosen === null && $strict && $lines !== []) {
            throw new ToolError(ToolError::INVALID_INPUT, (string) Loc::getMessage('ONETRACE_STORE_MCP_ORDER_NO_DELIVERY'));
        }

        $chosen ??= $methods[0] ?? null;
        $order->isStartField();
        $shipment->setFields($chosen !== null
            ? ['DELIVERY_ID' => (int) $chosen['id'], 'DELIVERY_NAME' => $chosen['name'], 'CURRENCY' => $order->getCurrency()]
            : ['DELIVERY_ID' => $empty, 'DELIVERY_NAME' => (string) (Deliveries::getById($empty)['NAME'] ?? ''), 'CURRENCY' => $order->getCurrency()]);
        $shipments->calculateDelivery();
        $order->doFinalAction(true);

        $payment = $order->getPaymentCollection()->createItem();
        $payment->setField('SUM', $order->getPrice());
        $payments = [];
        $paySystem = null;
        $skipped = [(int) PaySystems::getInnerPaySystemId(), (int) Config::get('loyalty_paysystem')];

        foreach ($lines !== [] ? PaySystems::getListWithRestrictions($payment) : [] as $row) {
            if (!in_array((int) $row['ID'], $skipped, true)) {
                $payments[] = ['id' => (string) $row['ID'], 'name' => (string) $row['NAME']];
                $paySystem = $paySystem === null || (string) $row['ID'] === $paymentMethod ? $row : $paySystem;
            }
        }

        if ($strict && $paymentMethod !== null && (string) ($paySystem['ID'] ?? '') !== $paymentMethod) {
            throw new ToolError(ToolError::INVALID_INPUT, (string) Loc::getMessage('ONETRACE_STORE_MCP_ORDER_NO_PAYMENT'));
        }

        if ($paySystem !== null) {
            $payment->setFields(['PAY_SYSTEM_ID' => (int) $paySystem['ID'], 'PAY_SYSTEM_NAME' => (string) $paySystem['NAME']]);
            $order->doFinalAction(true);
            $payment->setField('SUM', $order->getPrice());
        } else {
            $payment->delete();
        }

        return ['order' => $order, 'lines' => $lines, 'unavailable' => $unavailable, 'methods' => $methods, 'method' => $chosen['id'] ?? null, 'payments' => $payments];
    }

    /**
     * The answer of store_quote_order: lines at the prices with discounts, subtotal before them, the delivery.
     *
     * @param array{order: Order, lines: array<string, BasketItem>, unavailable: list<string>, methods: list<array{id: string, name: string, price: float, min_days: int|null, max_days: int|null}>, method: string|null, payments: list<array{id: string, name: string}>} $draft
     *
     * @return array<string, mixed>
     */
    private static function summary(array $draft): array
    {
        $order = $draft['order'];
        $currency = (string) $order->getCurrency();
        $items = [];
        $subtotal = 0.0;
        $charged = 0.0;

        foreach ($draft['lines'] as $id => $line) {
            $quantity = (float) $line->getQuantity();
            $items[] = [
                'product_id' => (string) $id,
                'name' => (string) $line->getField('NAME'),
                'quantity' => (int) $quantity,
                'price' => Tools::money((float) $line->getPrice(), $currency),
                'total' => Tools::money((float) $line->getFinalPrice(), $currency),
            ];
            $subtotal += (float) $line->getBasePrice() * $quantity;
            $charged += (float) $line->getFinalPrice();
        }

        $methods = [];

        foreach ($draft['methods'] as $method) {
            // The chosen service at the price of the order: with the delivery discounts of the store.
            $price = $method['id'] === $draft['method'] ? (float) $order->getDeliveryPrice() : $method['price'];
            $methods[] = Tools::filled(['id' => $method['id'], 'name' => $method['name'], 'price' => Tools::money($price, $currency), 'min_days' => $method['min_days'], 'max_days' => $method['max_days']]);
        }

        return [
            'items' => $items,
            'unavailable' => $draft['unavailable'],
            'subtotal' => Tools::money($subtotal, $currency),
            'discount' => Tools::money(max(0.0, $subtotal - $charged), $currency),
            'shipping_methods' => $methods,
            'shipping_method' => $draft['method'],
            'shipping_price' => Tools::money($draft['method'] !== null ? (float) $order->getDeliveryPrice() : 0.0, $currency),
            'total' => Tools::money((float) $order->getPrice(), $currency),
            'payment_methods' => $draft['payments'],
        ];
    }

    /**
     * The price of a product or of the chosen delivery is higher than in the last quote of these products: the
     * customer agreed to the quoted total, so the assistant has to quote again. Lower prices (a customer discount
     * that the quote without the customer did not know) are fine.
     *
     * @param list<array{product_id: string, quantity: int}> $items
     * @param array{order: Order, lines: array<string, BasketItem>, method: string|null} $draft
     */
    private static function checkPrices(string $siteId, array $items, array $draft): void
    {
        $quoted = (new CacheCodeStore())->get(self::quoteKey($siteId, $items));

        if ($quoted === null) {
            return;
        }

        $changed = [];

        foreach ($draft['lines'] as $id => $line) {
            $was = $quoted['prices'][$id] ?? null;

            if (is_numeric($was) && round((float) $line->getPrice(), 2) > round((float) $was, 2)) {
                $changed[] = (string) $line->getField('NAME');
            }
        }

        $was = $draft['method'] !== null ? ($quoted['shipping'][$draft['method']] ?? null) : null;

        if (is_numeric($was) && round((float) $draft['order']->getDeliveryPrice(), 2) > round((float) $was, 2)) {
            $changed[] = (string) Loc::getMessage('ONETRACE_STORE_MCP_ORDER_DELIVERY');
        }

        if ($changed !== []) {
            throw new ToolError(ToolError::INVALID_INPUT, (string) Loc::getMessage('ONETRACE_STORE_MCP_ORDER_PRICE_CHANGED', ['#WHAT#' => implode(', ', $changed)]));
        }
    }

    /**
     * The account of the order: the confirmed customer, or for a guest the account with the confirmed email or phone;
     * null — a guest without an account (place() makes one as the standard checkout does).
     */
    private static function account(Context $ctx): ?int
    {
        if ($ctx->customerId !== null) {
            $userIds = Customers::userIds($ctx->customerId);

            if ($userIds === []) {
                throw new ToolError(ToolError::AUTH_REQUIRED, (string) Loc::getMessage('ONETRACE_STORE_MCP_ORDER_NO_CUSTOMER'));
            }

            return $userIds[0];
        }

        $customer = Customers::find((string) $ctx->guestContact, null);
        $userIds = $customer !== null ? Customers::userIds($customer) : [];

        return $userIds[0] ?? null;
    }

    /**
     * The properties of the checkout from the address and the account: the payer, email, phone, address, ZIP and
     * city; required properties of the store that stay empty are an error with their names.
     *
     * @param array<string, string> $shipping
     */
    private static function fill(Order $order, array $shipping, Context $ctx, ?int $userId): void
    {
        $user = $userId !== null ? UserTable::getRow(['select' => ['EMAIL', 'PERSONAL_PHONE', 'PERSONAL_MOBILE'], 'filter' => ['=ID' => $userId]]) : null;
        $email = $ctx->guestEmail() ?? trim((string) ($user['EMAIL'] ?? ''));
        $phone = $shipping['phone'] ?? $ctx->guestPhone() ?? trim((string) (($user['PERSONAL_MOBILE'] ?? '') ?: ($user['PERSONAL_PHONE'] ?? '')));
        $properties = $order->getPropertyCollection();
        $properties->getPayerName()?->setValue(trim($shipping['first_name'] . ' ' . $shipping['last_name']));
        $properties->getAddress()?->setValue($shipping['address']);

        if ($email !== '') {
            $properties->getUserEmail()?->setValue($email);
        }

        if ($phone !== '') {
            $properties->getPhone()?->setValue($phone);
        }

        $missing = [];

        foreach ($properties as $value) {
            if ($value->isRequired() && !$value->isUtil() && !$value->checkRequiredValue($value->getPropertyId(), $value->getValue())->isSuccess()) {
                $missing[] = (string) $value->getName();
            }
        }

        if ($missing !== []) {
            throw new ToolError(ToolError::INVALID_INPUT, (string) Loc::getMessage('ONETRACE_STORE_MCP_ORDER_FIELDS', ['#FIELDS#' => implode(', ', $missing)]));
        }
    }

    /**
     * The location of the address for the delivery restrictions: the city (or the ZIP code of the location
     * import) in the country of the address; null — the store does not know it.
     *
     * @param array<string, string> $shipping
     */
    private static function location(array $shipping): ?string
    {
        $candidates = [];

        if (isset($shipping['city'])) {
            $rows = LocationTable::getList(['select' => ['ID', 'CODE'], 'filter' => ['=NAME.NAME' => $shipping['city'], '@TYPE.CODE' => ['CITY', 'VILLAGE']], 'limit' => 50]);

            while ($row = $rows->fetch()) {
                $candidates[(int) $row['ID']] = (string) $row['CODE'];
            }
        }

        if (isset($shipping['postcode'])) {
            $byZip = [];
            $rows = ExternalTable::getList(['select' => ['LOCATION_ID', 'CODE' => 'LOCATION.CODE'], 'filter' => ['@SERVICE.CODE' => ['ZIP', 'ZIP_LOWER'], '=XML_ID' => $shipping['postcode']], 'limit' => 50]);

            while ($row = $rows->fetch()) {
                $byZip[(int) $row['LOCATION_ID']] = (string) $row['CODE'];
            }

            // The city of the address wins; the ZIP code picks one of its namesakes or stands for an unknown city.
            $candidates = $candidates === [] ? $byZip : (array_intersect_key($candidates, $byZip) ?: $candidates);
        }

        $names = isset($shipping['country']) ? self::countryNames($shipping['country']) : [];

        foreach ($candidates as $id => $code) {
            if ($names === [] || self::inCountry($id, $names)) {
                return $code;
            }
        }

        if ($candidates === [] && $names !== []) {
            $country = LocationTable::getRow(['select' => ['CODE'], 'filter' => ['@NAME.NAME' => $names, '=TYPE.CODE' => 'COUNTRY']]);

            return is_array($country) ? (string) $country['CODE'] : null;
        }

        return null;
    }

    /**
     * Names of a country (ISO 3166-1 alpha-2) in the languages of the main module.
     *
     * @return list<string>
     */
    private static function countryNames(string $country): array
    {
        $names = [];

        foreach (array_unique(['ru', 'en', (string) LANGUAGE_ID]) as $language) {
            $name = (string) (\GetCountries($language)[$country]['NAME'] ?? '');

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * The location lies in a country with one of the names (the parents up to the country).
     *
     * @param list<string> $names
     */
    private static function inCountry(int $locationId, array $names): bool
    {
        $lower = array_map('mb_strtolower', $names);

        for ($depth = 0; $locationId > 0 && $depth < 10; $depth++) {
            $row = LocationTable::getRow(['select' => ['PARENT_ID', 'TYPE_CODE' => 'TYPE.CODE'], 'filter' => ['=ID' => $locationId]]);

            if (!is_array($row)) {
                return false;
            }

            if ((string) $row['TYPE_CODE'] === 'COUNTRY') {
                $rows = \Bitrix\Sale\Location\Name\LocationTable::getList(['select' => ['NAME'], 'filter' => ['=LOCATION_ID' => $locationId]]);

                while ($name = $rows->fetch()) {
                    if (in_array(mb_strtolower((string) $name['NAME']), $lower, true)) {
                        return true;
                    }
                }

                return false;
            }

            $locationId = (int) $row['PARENT_ID'];
        }

        return false;
    }

    /**
     * Delivery services restricted to locations.
     *
     * @return list<int>
     */
    private static function locationRestricted(): array
    {
        $ids = [];
        $class = \Bitrix\Sale\Delivery\Restrictions\ByLocation::class;
        $rows = \Bitrix\Sale\Internals\ServiceRestrictionTable::getList([
            'select' => ['SERVICE_ID'],
            'filter' => ['=SERVICE_TYPE' => \Bitrix\Sale\Services\Base\RestrictionManager::SERVICE_TYPE_SHIPMENT, '@CLASS_NAME' => [$class, '\\' . $class]],
        ]);

        while ($row = $rows->fetch()) {
            $ids[] = (int) $row['SERVICE_ID'];
        }

        return $ids;
    }

    /** The payer type of the checkout: the first active one of the site. */
    private static function personType(string $siteId): int
    {
        $row = PersonTypeTable::getRow(['select' => ['ID'], 'filter' => ['=ACTIVE' => 'Y', '=PERSON_TYPE_SITE.SITE_ID' => $siteId], 'order' => ['SORT' => 'ASC', 'ID' => 'ASC']]);

        return (int) ($row['ID'] ?? 0);
    }

    /**
     * Paid on delivery: cash (the handler "cash" or a cash payment system).
     *
     * @param array<string, mixed> $paySystem
     */
    private static function onDelivery(array $paySystem): bool
    {
        return ($paySystem['IS_CASH'] ?? '') === 'Y' || in_array((string) ($paySystem['ACTION_FILE'] ?? ''), ['cash', 'cashondelivery', 'cashondeliverycalc'], true);
    }

    /** The manager comment of the order: where it came from. */
    private static function source(Context $ctx): string
    {
        if (!$ctx->fromAssistant()) {
            return (string) Loc::getMessage('ONETRACE_STORE_MCP_ORDER_FROM_CHAT');
        }

        $name = self::ASSISTANTS[(string) $ctx->assistant] ?? (string) Loc::getMessage('ONETRACE_STORE_MCP_ORDER_OTHER_ASSISTANT');

        return (string) Loc::getMessage('ONETRACE_STORE_MCP_ORDER_FROM_ASSISTANT', ['#ASSISTANT#' => $name]);
    }

    /**
     * @param list<array{product_id: string, quantity: int}> $items
     */
    private static function quoteKey(string $siteId, array $items): string
    {
        $quantities = array_column($items, 'quantity', 'product_id');
        ksort($quantities, SORT_STRING);

        return 'quote|' . $siteId . '|' . sha1((string) json_encode($quantities));
    }
}
