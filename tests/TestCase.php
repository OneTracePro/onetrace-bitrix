<?php

declare(strict_types=1);

namespace Onetrace\Connector\Tests;

use Bitrix\Catalog\GroupTable;
use Bitrix\Catalog\Model\Price;
use Bitrix\Catalog\Model\Product;
use Bitrix\Sale\Basket;
use Bitrix\Sale\Delivery\Services\EmptyDeliveryService;
use Bitrix\Sale\Delivery\Services\Manager as Deliveries;
use Bitrix\Sale\Order;
use Bitrix\Sale\PaySystem\Manager as PaySystems;
use JsonSchema\Validator;
use Onetrace\Connector\Config;
use Onetrace\Connector\Connection;
use Onetrace\Connector\Queue;
use Onetrace\Connector\Tests\Support\RecordingTransport;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected const SITE = 's1';

    protected RecordingTransport $platform;

    private static int $iblockId = 0;

    protected function setUp(): void
    {
        foreach (['url' => 'https://cdp.example.com', 'write_key' => 'cdp_wk_test', 'secret_key' => 'cdp_sk_test', 'consent_property' => '', 'consent_topic' => '', 'push' => 'N'] as $name => $value) {
            Config::set($name, $value, self::SITE);
        }

        foreach (Config::PARTS as $part) {
            Config::set('part_' . $part, 'Y', self::SITE);
        }

        Queue::clear();
        Queue::resume();
        $this->platform = new RecordingTransport();
        Connection::reset(['transport' => $this->platform]);
        $_COOKIE = [];
        \Bitrix\Main\Application::getInstance()->getContext()->getRequest()->getCookieRawList()->set([]);
    }

    /**
     * The tracker cookie of the current request.
     */
    protected function visitor(?string $anonymousId): void
    {
        $cookies = $anonymousId !== null ? ['cdp_aid' => $anonymousId] : [];
        $_COOKIE = $cookies;
        \Bitrix\Main\Application::getInstance()->getContext()->getRequest()->getCookieRawList()->set($cookies);
    }

    /**
     * A product of the test catalog infoblock with a base price and stock.
     */
    protected function product(string $name = 'Sneakers', float $price = 49.95, ?int $sectionId = null): int
    {
        $element = new \CIBlockElement();
        $id = (int) $element->Add([
            'IBLOCK_ID' => self::catalogIblock(), 'NAME' => $name, 'ACTIVE' => 'Y', 'CODE' => 'onetrace-' . bin2hex(random_bytes(4)),
            'IBLOCK_SECTION_ID' => $sectionId,
        ]);
        self::assertGreaterThan(0, $id, (string) $element->LAST_ERROR);
        Product::add(['ID' => $id, 'QUANTITY' => 100, 'TYPE' => \Bitrix\Catalog\ProductTable::TYPE_PRODUCT]);
        Price::add(['PRODUCT_ID' => $id, 'CATALOG_GROUP_ID' => (int) GroupTable::getRow(['select' => ['ID'], 'filter' => ['=BASE' => 'Y']])['ID'], 'PRICE' => $price, 'CURRENCY' => 'RUB']);

        return $id;
    }

    protected function section(string $name, ?int $parentId = null): int
    {
        $section = new \CIBlockSection();
        $id = (int) $section->Add(['IBLOCK_ID' => self::catalogIblock(), 'NAME' => $name, 'IBLOCK_SECTION_ID' => $parentId, 'ACTIVE' => 'Y', 'CODE' => 'onetrace-' . bin2hex(random_bytes(4))]);
        self::assertGreaterThan(0, $id, (string) $section->LAST_ERROR);

        return $id;
    }

    /**
     * A customer account; the email is unique per call (users with orders cannot be deleted).
     */
    protected function user(?string $email = null): int
    {
        $email ??= 'anna-' . bin2hex(random_bytes(4)) . '@example.com';
        $user = new \CUser();
        $id = (int) $user->Add([
            'LOGIN' => $email, 'EMAIL' => $email, 'NAME' => 'Anna', 'LAST_NAME' => 'Schmidt', 'PERSONAL_PHONE' => '+7 900 123-45-67',
            'PASSWORD' => 'Passw0rd!' . bin2hex(random_bytes(4)), 'ACTIVE' => 'Y', 'LID' => self::SITE,
        ]);
        self::assertGreaterThan(0, $id, (string) $user->LAST_ERROR);

        return $id;
    }

    /**
     * An order through the sale API (basket → shipment → payment), as the checkout makes it.
     */
    /**
     * @param array<string, string> $properties order property values by code
     */
    protected function order(int $productId, int $userId, int $quantity = 2, string $email = 'anna@example.com', array $properties = [], ?callable $beforeSave = null): Order
    {
        $basket = Basket::create(self::SITE);
        $item = $basket->createItem('catalog', $productId);
        $item->setFields(['QUANTITY' => $quantity, 'CURRENCY' => 'RUB', 'LID' => self::SITE, 'PRODUCT_PROVIDER_CLASS' => \Bitrix\Catalog\Product\CatalogProvider::class]);

        $order = Order::create(self::SITE, $userId);
        $order->setPersonTypeId(self::personType());
        $order->setBasket($basket);
        $shipment = $order->getShipmentCollection()->createItem(Deliveries::getObjectById(EmptyDeliveryService::getEmptyDeliveryServiceId()));

        foreach ($basket as $basketItem) {
            $shipment->getShipmentItemCollection()->createItem($basketItem)->setQuantity($basketItem->getQuantity());
        }

        // Not the inner account: it needs the customer's balance to be paid.
        $paySystem = \Bitrix\Sale\Internals\PaySystemActionTable::getRow(['select' => ['ID'], 'filter' => ['=ACTIVE' => 'Y', '!=ID' => PaySystems::getInnerPaySystemId()], 'order' => ['SORT' => 'ASC']]);
        $payment = $order->getPaymentCollection()->createItem(PaySystems::getObjectById((int) $paySystem['ID']));
        $payment->setField('SUM', $order->getPrice());
        $collection = $order->getPropertyCollection();
        $collection->getUserEmail()?->setValue($email);
        $collection->getPayerName()?->setValue('Anna Schmidt');
        $collection->getPhone()?->setValue('+7 900 123-45-67');

        foreach ($properties as $code => $value) {
            $collection->getItemByOrderPropertyCode($code)?->setValue($value);
        }

        if ($beforeSave !== null) {
            $beforeSave($order);
        }

        $result = $order->save();
        self::assertTrue($result->isSuccess(), implode('; ', $result->getErrorMessages()));

        return $order;
    }

    /**
     * @return array{sent: int, retried: int, dropped: int}
     */
    protected function flush(): array
    {
        return Queue::flush(PHP_INT_MAX);
    }

    /**
     * @param array<string, mixed> $message
     */
    protected static function assertContract(array $message): void
    {
        $validator = new Validator();
        $data = json_decode((string) json_encode($message));
        $validator->validate($data, (object) ['$ref' => 'file://' . dirname(__DIR__) . '/vendor/onetracepro/onetrace-php/resources/ecommerce-events.schema.json']);

        self::assertTrue($validator->isValid(), (string) json_encode(['message' => $message, 'errors' => $validator->getErrors()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * The test catalog infoblock of the site (created once).
     */
    protected static function catalogIblock(): int
    {
        if (self::$iblockId > 0) {
            return self::$iblockId;
        }

        $existing = \CIBlock::GetList([], ['CODE' => 'onetrace_test', 'CHECK_PERMISSIONS' => 'N'])->Fetch();

        if (is_array($existing)) {
            return self::$iblockId = (int) $existing['ID'];
        }

        if (!\CIBlockType::GetByID('onetrace_test')->Fetch()) {
            (new \CIBlockType())->Add(['ID' => 'onetrace_test', 'SECTIONS' => 'Y', 'LANG' => ['ru' => ['NAME' => 'OneTrace tests'], 'en' => ['NAME' => 'OneTrace tests']]]);
        }

        $id = (int) (new \CIBlock())->Add([
            'IBLOCK_TYPE_ID' => 'onetrace_test', 'CODE' => 'onetrace_test', 'NAME' => 'Catalog', 'LID' => [self::SITE], 'ACTIVE' => 'Y',
            'DETAIL_PAGE_URL' => '#SITE_DIR#/catalog/#ELEMENT_CODE#/', 'SECTION_PAGE_URL' => '#SITE_DIR#/catalog/#SECTION_CODE#/', 'GROUP_ID' => ['2' => 'R'],
        ]);
        \CCatalog::Add(['IBLOCK_ID' => $id]);

        return self::$iblockId = $id;
    }

    protected static function personType(): int
    {
        $row = \Bitrix\Sale\Internals\PersonTypeTable::getRow(['select' => ['ID'], 'filter' => ['=ACTIVE' => 'Y', '=PERSON_TYPE_SITE.SITE_ID' => self::SITE], 'order' => ['SORT' => 'ASC']]);

        return (int) ($row['ID'] ?? 1);
    }
}
