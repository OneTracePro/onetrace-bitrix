<?php

declare(strict_types=1);

namespace Onetrace\Connector;

use Bitrix\Main\Application;
use Bitrix\Main\Mail\Internal\EventMessageTable;
use Bitrix\Sale\BasketItem;
use Bitrix\Sale\Delivery\Services\EmptyDeliveryService;
use Bitrix\Sale\Delivery\Tracking\Manager as Tracking;
use Bitrix\Sale\Order;
use Bitrix\Sale\Payment;
use Bitrix\Sale\Shipment;
use Bitrix\Sale\ShipmentItem;
use OneTrace\Commerce\LineItem;
use OneTrace\Exception\AuthenticationException;
use OneTrace\Exception\PermissionException;
use OneTrace\Resource\Emails;

/**
 * Store emails through OneTrace (the "Store emails" section of the platform): the settings of a site cached for
 * a few minutes, the kinds of Bitrix mail events, the order data of the OneTrace order templates and the emails the
 * module sends itself — shipped, delivered and refunded orders the site has no email for.
 *
 * Bitrix mail events → kinds (KINDS) of the customer recipients; copies to shop addresses (the sender address, the
 * order email of the "Online store", "Copies of all emails", CC and BCC) never go through OneTrace — Bitrix sends
 * them. Other events of the shop modules (SALE_*, CATALOG_*) — "other"; events of other modules (forum, blog,
 * event log …) stay with Bitrix.
 */
final class StoreEmails
{
    /** Cached settings live this long (seconds); a failed request — FAILURE_TTL, then the module asks again. */
    public const TTL = 300;

    public const FAILURE_TTL = 60;

    /** Request timeouts of the mail path (seconds): a mail event of Bitrix waits for the answer. */
    public const TIMEOUT = 5.0;

    /** Attachment types the platform accepts, at most 5 files and 5 MB in total. */
    public const ATTACHMENT_TYPES = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'text/plain', 'text/csv', 'text/calendar'];

    public const MAX_ATTACHMENTS = 5;

    public const MAX_ATTACHMENT_BYTES = 5 * 1024 * 1024;

    /** @var array<string, string> mail event type => kind */
    public const KINDS = [
        'SALE_NEW_ORDER' => 'order_placed',
        'SALE_NEW_ORDER_RECURRING' => 'order_placed',
        'SALE_ORDER_PAID' => 'order_paid',
        'SALE_ORDER_CANCEL' => 'order_cancelled',
        'SALE_ORDER_DELIVERY' => 'order_shipped',
        'SALE_ORDER_TRACKING_NUMBER' => 'order_shipped',
        'SALE_ORDER_REMIND_PAYMENT' => 'order_note',
        'SALE_ORDER_ALLOW_PAY' => 'order_note',
        'SALE_ORDER_SHIPMENT_STATUS_CHANGED' => 'order_note',
        'SALE_CHECK_PRINT' => 'invoice',
        'USER_PASS_REQUEST' => 'password_reset',
        'USER_INFO' => 'account_new',
        'USER_INVITE' => 'account_new',
        'NEW_USER' => 'account_new',
        'NEW_USER_CONFIRM' => 'email_verification',
        'SUBSCRIBE_CONFIRM' => 'email_verification',
        'SENDER_SUBSCRIBE_CONFIRM' => 'email_verification',
        'USER_CODE_REQUEST' => 'login_code',
        'CATALOG_PRODUCT_SUBSCRIBE_LIST_CONFIRM' => 'login_code',
        'USER_PASS_CHANGED' => 'other',
        'NEW_DEVICE_LOGIN' => 'other',
    ];

    private const CACHE_DIR = 'onetrace_connector';

    /**
     * The kind of a mail event of a site; null — not a store email (Bitrix sends it). SALE_STATUS_CHANGED_<status>:
     * a status of the "Order statuses “Delivered”" setting — order_delivered, another one — order_note.
     */
    public static function kind(string $eventName, string $siteId): ?string
    {
        if (isset(self::KINDS[$eventName])) {
            return self::KINDS[$eventName];
        }

        if (str_starts_with($eventName, 'SALE_STATUS_CHANGED_')) {
            return in_array(substr($eventName, strlen('SALE_STATUS_CHANGED_')), Config::deliveredStatuses($siteId), true) ? 'order_delivered' : 'order_note';
        }

        return str_starts_with($eventName, 'SALE_') || str_starts_with($eventName, 'CATALOG_') ? 'other' : null;
    }

    /**
     * Settings of the "Store emails" section for the secret key of a site, cached (TTL); null — no secret key or
     * the platform did not answer (the shop sends its emails itself).
     *
     * @return array{relay: bool, integration_id: int|null, templates: array<string, bool>}|null
     */
    public static function settings(string $siteId): ?array
    {
        if (!Config::server($siteId)) {
            return null;
        }

        $cache = Application::getInstance()->getManagedCache();
        $id = 'onetrace_emails_' . md5(Config::url($siteId) . '|' . Config::secretKey($siteId));
        $value = $cache->read(self::TTL, $id, self::CACHE_DIR) ? $cache->get($id) : false;

        // Long agents and cron keep the cache of their first read: the time of the value decides.
        if (is_array($value) && ($value['at'] ?? 0) >= time() - (($value['settings'] ?? null) === null ? self::FAILURE_TTL : self::TTL)) {
            return $value['settings'];
        }

        try {
            $settings = Connection::client($siteId, 'emails')->emails()->settings();
            $error = '';
        } catch (\Throwable $exception) {
            $settings = null;
            $error = $exception instanceof PermissionException || $exception instanceof AuthenticationException ? 'permission' : mb_substr($exception->getMessage(), 0, 500);

            if ($error !== 'permission') {
                Queue::log('store email settings of site ' . $siteId . ': ' . $error);
            }
        }

        $cache->clean($id, self::CACHE_DIR);
        $cache->read(self::TTL, $id, self::CACHE_DIR);
        $cache->setImmediate($id, ['at' => time(), 'settings' => $settings, 'error' => $error]);

        return $settings;
    }

    /** Why the settings of a site are unavailable: "permission" (the key lacks messages.send), an error text or ''. */
    public static function settingsError(string $siteId): string
    {
        $cache = Application::getInstance()->getManagedCache();
        $id = 'onetrace_emails_' . md5(Config::url($siteId) . '|' . Config::secretKey($siteId));
        $value = $cache->read(self::TTL, $id, self::CACHE_DIR) ? $cache->get($id) : false;

        return is_array($value) ? (string) ($value['error'] ?? '') : '';
    }

    /** Forgets the cached settings of all sites (the module settings were saved). */
    public static function forget(): void
    {
        Application::getInstance()->getManagedCache()->cleanDir(self::CACHE_DIR);
    }

    /** Emails of an order kind of the site are built from a OneTrace template. */
    public static function templated(string $siteId, string $kind): bool
    {
        $settings = self::settings($siteId);

        return $settings !== null && Emails::route($settings, $kind) === Emails::TEMPLATE;
    }

    /**
     * Sends a store email. The mail path ($queued false) waits at most TIMEOUT and does not retry; the queue — the
     * usual client. Throws when the platform did not accept the email.
     *
     * @param array<string, mixed> $email
     *
     * @return array<string, mixed>
     */
    public static function send(string $siteId, array $email, string $key, bool $queued = false): array
    {
        $settings = self::settings($siteId);

        if (($settings['integration_id'] ?? null) !== null && !isset($email['integration_id'])) {
            $email['integration_id'] = $settings['integration_id'];
        }

        return Connection::client($siteId, $queued ? 'secret' : 'emails')->emails()->send($email, $key);
    }

    /**
     * Idempotency key of an email: the address of the site keeps keys of two shops on one project apart.
     */
    public static function key(string $siteId, string ...$parts): string
    {
        return 'bitrix-' . substr(md5(Connection::siteUrl($siteId)), 0, 10) . '-' . implode('-', $parts);
    }

    /**
     * An email of an order kind from the OneTrace template: the customer's address (the order's by default), the
     * language of the site, the first name, and the order with its lines, links, addresses and the kind's details
     * (the shipment of order_shipped, the returned sum of order_refunded, the reason of order_cancelled). Null — the
     * order has no customer address.
     *
     * @return array<string, mixed>|null
     */
    public static function orderEmail(Order $order, string $kind, ?Shipment $shipment = null, ?float $refund = null, ?string $to = null): ?array
    {
        $siteId = (string) $order->getSiteId();
        $model = Mapper::order($order);
        $traits = $model->customer->traits();
        $to ??= (string) ($traits['email'] ?? '');

        if ($to === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $base = self::origin($siteId);
        $model->lines = self::lines($shipment !== null && $kind === 'order_shipped' ? self::shipmentItems($shipment) : ($order->getBasket()?->getOrderableItems() ?? []), $base);
        $extra = ['url' => self::orderUrl($order), 'shipping_address' => self::address($order)];

        if ($kind === 'order_shipped' && $shipment !== null) {
            $extra += self::tracking($shipment);
        }

        if ($kind === 'order_refunded') {
            $extra['refund_amount'] = $refund ?? self::returned($order);
        }

        if ($kind === 'order_cancelled') {
            $extra['reason'] = (string) $order->getField('REASON_CANCELED');
        }

        return array_filter([
            'to' => $to,
            'kind' => $kind,
            'language' => Config::language($siteId),
            'traits' => array_filter(['first_name' => $traits['first_name'] ?? null, 'last_name' => $traits['last_name'] ?? null]) ?: null,
            'order' => $model->emailData($extra),
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * Carrier, tracking number and its link of a shipment ("Без доставки" is not a carrier).
     *
     * @return array{carrier?: string, tracking_number?: string, tracking_url?: string}
     */
    public static function tracking(Shipment $shipment): array
    {
        $deliveryId = (int) $shipment->getDeliveryId();
        $tracking = trim((string) $shipment->getField('TRACKING_NUMBER'));
        $carrier = $deliveryId !== (int) EmptyDeliveryService::getEmptyDeliveryServiceId() ? trim((string) $shipment->getDeliveryName()) : '';
        $url = '';

        if ($tracking !== '' && $deliveryId > 0) {
            try {
                $url = trim((string) Tracking::getInstance()->getTrackingUrl($deliveryId, $tracking));
            } catch (\Throwable) {
                $url = '';
            }
        }

        return array_filter(['carrier' => $carrier, 'tracking_number' => $tracking, 'tracking_url' => $url], static fn (string $value): bool => $value !== '');
    }

    /**
     * A queued email of the module (Queue::EMAIL): order_shipped of a shipment, order_delivered and order_refunded
     * of an order — sent when the kind is still on a template; the shipped one only when the site did not send its own
     * email for the shipment (the native tracking number or "delivery allowed" email, which goes through the template
     * itself). Throws to retry.
     *
     * @param array<string, mixed> $job kind, order, shipment (order_shipped), refund (the returned sum), key
     */
    public static function deliver(string $siteId, array $job): void
    {
        $settings = self::settings($siteId);

        if ($settings === null) {
            if (self::settingsError($siteId) === 'permission') {
                return;
            }

            throw new \RuntimeException('store email settings are unavailable');
        }

        $kind = (string) ($job['kind'] ?? '');
        $order = (int) ($job['order'] ?? 0) > 0 ? Order::load((int) $job['order']) : null;

        if (Emails::route($settings, $kind) !== Emails::TEMPLATE || !$order instanceof Order) {
            return;
        }

        $shipment = null;
        $refund = null;

        if ($kind === 'order_shipped') {
            $shipment = $order->getShipmentCollection()->getItemById((int) ($job['shipment'] ?? 0));

            if (!$shipment instanceof Shipment || self::nativeShipped($siteId, $shipment)) {
                return;
            }
        }

        if ($kind === 'order_refunded') {
            $refund = is_numeric($job['refund'] ?? null) ? (float) $job['refund'] : null;
        }

        $email = self::orderEmail($order, $kind, $shipment, $refund);

        if ($email !== null) {
            self::send($siteId, $email, (string) $job['key'], true);
        }
    }

    /**
     * The site has an active email template of a mail event type.
     */
    public static function hasTemplate(string $siteId, string $eventName): bool
    {
        return EventMessageTable::getCount(['=ACTIVE' => 'Y', '=EVENT_NAME' => $eventName, '=EVENT_MESSAGE_SITE.SITE_ID' => $siteId]) > 0;
    }

    /**
     * Bitrix sends its own email of an order status: the status notifies the customer and the site has its template.
     */
    public static function nativeStatus(string $siteId, string $statusId): bool
    {
        $status = \CSaleStatus::GetByID($statusId, Config::language($siteId));

        return is_array($status) && ($status['NOTIFY'] ?? 'N') === 'Y' && self::hasTemplate($siteId, 'SALE_STATUS_CHANGED_' . $statusId);
    }

    /**
     * Bitrix sent its own email of a shipment: its tracking number or "delivery allowed".
     */
    private static function nativeShipped(string $siteId, Shipment $shipment): bool
    {
        return (trim((string) $shipment->getField('TRACKING_NUMBER')) !== '' && self::hasTemplate($siteId, 'SALE_ORDER_TRACKING_NUMBER'))
            || ($shipment->getField('ALLOW_DELIVERY') === 'Y' && self::hasTemplate($siteId, 'SALE_ORDER_DELIVERY'));
    }

    /** Scheme and host of a site: "https://shop.example.com". */
    public static function origin(string $siteId): string
    {
        $url = Connection::siteUrl($siteId);
        $parts = parse_url($url);

        return isset($parts['scheme'], $parts['host']) ? $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') : rtrim($url, '/');
    }

    /**
     * The page of the order: the guest link of the "Online store" when its statuses allow it, otherwise the order in
     * the personal section.
     */
    private static function orderUrl(Order $order): string
    {
        try {
            if (\Bitrix\Sale\Helpers\Order::isAllowGuestView($order)) {
                $link = (string) \Bitrix\Sale\Helpers\Order::getPublicLink($order);

                if (preg_match('#^https?://#i', $link) === 1) {
                    return $link;
                }
            }
        } catch (\Throwable) {
        }

        return Connection::siteUrl((string) $order->getSiteId()) . 'personal/order/detail/' . rawurlencode((string) ($order->getField('ACCOUNT_NUMBER') ?: $order->getId())) . '/';
    }

    /**
     * The delivery address from the order properties: the payer, the company, the address, the location, the ZIP code
     * and the phone.
     *
     * @return array<string, string>
     */
    private static function address(Order $order): array
    {
        $properties = $order->getPropertyCollection();
        $value = static fn ($property): string => is_object($property) && is_scalar($property->getValue()) ? trim((string) $property->getValue()) : '';
        $location = $value($properties->getDeliveryLocation());
        $place = '';

        if ($location !== '') {
            try {
                $place = (string) \Bitrix\Sale\Location\Admin\LocationHelper::getLocationStringByCode($location, ['INVERSE' => false, 'DELIMITER' => ', ', 'LANGUAGE_ID' => Config::language((string) $order->getSiteId())]);
            } catch (\Throwable) {
                $place = '';
            }
        }

        return array_filter([
            'name' => $value($properties->getPayerName()),
            'company' => $value($properties->getItemByOrderPropertyCode('COMPANY')),
            'address1' => $value($properties->getAddress()),
            'city' => $value($properties->getItemByOrderPropertyCode('CITY')) ?: $place,
            'postcode' => $value($properties->getDeliveryLocationZip()),
            'phone' => $value($properties->getPhone()),
        ], static fn (string $part): bool => $part !== '');
    }

    /**
     * Lines of the email: the products with the article, the picture, the link and the options of the line (the
     * properties of the offer in the cart).
     *
     * @param iterable<array{0: BasketItem, 1: int|null}|BasketItem> $items
     *
     * @return list<LineItem>
     */
    private static function lines(iterable $items, string $base): array
    {
        $lines = [];

        foreach ($items as $entry) {
            [$item, $quantity] = is_array($entry) ? $entry : [$entry, null];
            $display = ['url' => self::absolute((string) $item->getField('DETAIL_PAGE_URL'), $base)];
            $options = [];

            foreach ($item->getPropertyCollection()->getPropertyValues() as $code => $property) {
                $name = trim((string) ($property['NAME'] ?? ''));
                $text = trim((string) ($property['VALUE'] ?? ''));

                if (in_array($code, ['ARTNUMBER', 'ARTICLE', 'CML2_ARTICLE'], true)) {
                    $display['sku'] = $text;
                } elseif ($name !== '' && $text !== '' && !str_contains((string) $code, 'XML_ID')) {
                    $options[] = $name . ': ' . $text;
                }
            }

            $display['options'] = implode(', ', $options);
            $display['image_url'] = self::picture((int) $item->getProductId(), $base);
            $lines[] = Mapper::line($item, $quantity)->withDisplay($display);
        }

        return $lines;
    }

    /**
     * @return list<array{0: BasketItem, 1: int}>
     */
    private static function shipmentItems(Shipment $shipment): array
    {
        $items = [];

        /** @var ShipmentItem $item */
        foreach ($shipment->getShipmentItemCollection() as $item) {
            $basketItem = $item->getBasketItem();

            if ($basketItem instanceof BasketItem && $item->getQuantity() > 0) {
                $items[] = [$basketItem, (int) $item->getQuantity()];
            }
        }

        return $items;
    }

    /** The picture of an offer or, without one, of its product. */
    private static function picture(int $elementId, string $base): string
    {
        [$productId] = Catalog::productOf($elementId);

        foreach (array_unique([$elementId, (int) $productId]) as $id) {
            $element = $id > 0 ? \CIBlockElement::GetList([], ['ID' => $id], false, false, ['ID', 'DETAIL_PICTURE', 'PREVIEW_PICTURE'])->Fetch() : false;
            $picture = is_array($element) ? (int) ($element['DETAIL_PICTURE'] ?: $element['PREVIEW_PICTURE']) : 0;

            if ($picture > 0 && ($path = (string) \CFile::GetPath($picture)) !== '') {
                return self::absolute($path, $base);
            }
        }

        return '';
    }

    /** The sum of the returned payments of an order. */
    private static function returned(Order $order): float
    {
        $sum = 0.0;

        /** @var Payment $payment */
        foreach ($order->getPaymentCollection() as $payment) {
            if (in_array($payment->getField('IS_RETURN'), ['Y', 'P'], true)) {
                $sum += (float) $payment->getSum();
            }
        }

        return $sum;
    }

    /** An absolute link of a site path ("/catalog/…", "//cdn…"); empty stays empty. */
    public static function absolute(string $path, string $base): string
    {
        $path = trim($path);

        if ($path === '' || preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        if (str_starts_with($path, '//')) {
            return (string) parse_url($base, PHP_URL_SCHEME) . ':' . $path;
        }

        return rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}
