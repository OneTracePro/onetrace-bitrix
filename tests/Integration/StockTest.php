<?php

declare(strict_types=1);

namespace Onetrace\Connector\Tests\Integration;

use Bitrix\Catalog\Model\Product;
use Bitrix\Catalog\ProductTable;
use Bitrix\Main\Config\Option;
use Onetrace\Connector\Tests\TestCase;

/**
 * Stock of the catalog items: sent when the product keeps count, the sum of the offers for a product with offers; the
 * product is uploaded again when its quantity changes (orders, the admin, store documents).
 */
final class StockTest extends TestCase
{
    private string $traceDefault = '';

    private string $zeroDefault = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->traceDefault = Option::get('catalog', 'default_quantity_trace');
        $this->zeroDefault = Option::get('catalog', 'default_can_buy_zero');
    }

    protected function tearDown(): void
    {
        Option::set('catalog', 'default_quantity_trace', $this->traceDefault);
        Option::set('catalog', 'default_can_buy_zero', $this->zeroDefault);
        ProductTable::loadDefaultProductSettings();
        parent::tearDown();
    }

    public function testSendsTheStockOnlyWhenTheProductKeepsCount(): void
    {
        $product = $this->product('Wool Socks', 9.0);
        Product::update($product, ['QUANTITY' => 7, 'QUANTITY_TRACE' => 'Y']);
        $this->flush();

        self::assertSame(7, $this->uploaded($product)['stock']);

        Product::update($product, ['QUANTITY_TRACE' => 'N']);
        $this->flush();

        self::assertArrayNotHasKey('stock', $this->uploaded($product));
        self::assertTrue($this->uploaded($product)['available']);
    }

    public function testTheDefaultTracingOfTheCatalogModule(): void
    {
        $product = $this->product('Cap', 15.0);
        Product::update($product, ['QUANTITY' => 4, 'QUANTITY_TRACE' => 'D']);

        Option::set('catalog', 'default_quantity_trace', 'N');
        ProductTable::loadDefaultProductSettings();
        $this->flush();
        self::assertArrayNotHasKey('stock', $this->uploaded($product));

        Option::set('catalog', 'default_quantity_trace', 'Y');
        ProductTable::loadDefaultProductSettings();
        \Onetrace\Connector\Queue::product($product);
        $this->flush();
        self::assertSame(4, $this->uploaded($product)['stock']);
    }

    public function testNoStockForProductsSoldAtZero(): void
    {
        // Zero stock of a product sold at zero does not mean "not available": the platform must not take it off sale.
        $product = $this->product('Pre-order Jacket', 99.0);
        Product::update($product, ['QUANTITY' => 0, 'QUANTITY_TRACE' => 'Y', 'CAN_BUY_ZERO' => 'Y']);
        $this->flush();
        self::assertArrayNotHasKey('stock', $this->uploaded($product));
        self::assertTrue($this->uploaded($product)['available']);

        // "Default" of the product follows the catalog module.
        Product::update($product, ['QUANTITY' => 2, 'CAN_BUY_ZERO' => 'D']);
        Option::set('catalog', 'default_can_buy_zero', 'Y');
        ProductTable::loadDefaultProductSettings();
        $this->flush();
        self::assertArrayNotHasKey('stock', $this->uploaded($product));

        Option::set('catalog', 'default_can_buy_zero', 'N');
        ProductTable::loadDefaultProductSettings();
        \Onetrace\Connector\Queue::product($product);
        $this->flush();
        self::assertSame(2, $this->uploaded($product)['stock']);

        // A product with offers: one offer sold at zero leaves the total unknown.
        $parent = $this->product('Pre-order Boots', 60.0);
        [, $second] = $this->offers($parent, [[3, 'Y'], [1, 'Y']]);
        $this->flush();
        self::assertSame(4, $this->uploaded($parent)['stock']);

        Product::update($second, ['CAN_BUY_ZERO' => 'Y']);
        $this->flush();
        self::assertArrayNotHasKey('stock', $this->uploaded($parent));
    }

    public function testAProductWithOffersSumsTheOffersInStock(): void
    {
        $product = $this->product('Hoodie', 30.0);
        [, $second] = $this->offers($product, [[3, 'Y'], [4, 'Y'], [0, 'Y']]);
        $this->flush();

        self::assertSame(7, $this->uploaded($product)['stock']);

        // An offer sold out by the admin: the product is uploaded again with the new total.
        $this->platform->requests = [];
        Product::update($second, ['QUANTITY' => 0]);
        $this->flush();
        self::assertSame(3, $this->uploaded($product)['stock']);

        // An offer without quantity tracing: the total is unknown.
        Product::update($second, ['QUANTITY_TRACE' => 'N']);
        $this->flush();
        self::assertArrayNotHasKey('stock', $this->uploaded($product));
    }

    public function testAnOrderAndItsCancellationUploadTheNewStock(): void
    {
        $product = $this->product('Trail Shoes', 80.0);
        Product::update($product, ['QUANTITY' => 10, 'QUANTITY_TRACE' => 'Y', 'CAN_BUY_ZERO' => 'N']);
        $this->flush();
        $this->platform->requests = [];

        // The reservation of the order (at payment, delivery or creation by the settings of the store): the quantity
        // of the product goes down.
        $order = $this->order($product, $this->user(), 3);
        $order = \Bitrix\Sale\Order::load((int) $order->getId());

        foreach ($order->getShipmentCollection()->getNotSystemItems() as $shipment) {
            self::assertTrue($shipment->tryReserve()->isSuccess());
        }

        self::assertTrue($order->save()->isSuccess());
        $this->flush();
        self::assertSame(7, $this->uploaded($product)['stock']);

        $this->platform->requests = [];
        $order = \Bitrix\Sale\Order::load((int) $order->getId());
        self::assertTrue($order->setField('CANCELED', 'Y')->isSuccess());
        self::assertTrue($order->save()->isSuccess());
        $this->flush();
        self::assertSame(10, $this->uploaded($product)['stock']);
    }

    public function testAStoreDocumentUploadsTheNewStock(): void
    {
        if (!\Bitrix\Catalog\Config\State::isUsedInventoryManagement()) {
            self::markTestSkipped('Inventory management is off.');
        }

        $product = $this->product('Beanie', 12.0);
        Product::update($product, ['QUANTITY_TRACE' => 'Y']);
        $quantity = (int) ProductTable::getRow(['select' => ['QUANTITY'], 'filter' => ['=ID' => $product]])['QUANTITY'];
        $this->flush();
        $this->platform->requests = [];

        $store = \Bitrix\Catalog\StoreTable::getRow(['select' => ['ID'], 'filter' => ['=ACTIVE' => 'Y'], 'order' => ['SORT' => 'ASC', 'ID' => 'ASC']]);
        self::assertNotNull($store, 'The demo shop has a warehouse.');
        $documentId = (int) \CCatalogDocs::add([
            'DOC_TYPE' => \Bitrix\Catalog\StoreDocumentTable::TYPE_STORE_ADJUSTMENT, 'SITE_ID' => self::SITE, 'CURRENCY' => 'RUB',
            'CREATED_BY' => 1, 'MODIFIED_BY' => 1, 'RESPONSIBLE_ID' => 1, 'DATE_DOCUMENT' => new \Bitrix\Main\Type\DateTime(),
        ]);
        self::assertGreaterThan(0, $documentId, self::bitrixError());
        self::assertGreaterThan(0, (int) \CCatalogStoreDocsElement::add([
            'DOC_ID' => $documentId, 'ELEMENT_ID' => $product, 'STORE_TO' => (int) $store['ID'], 'AMOUNT' => 5, 'PURCHASING_PRICE' => 6, 'BASE_PRICE' => 12,
        ]));
        self::assertTrue((bool) \CCatalogDocs::conductDocument($documentId, 1), self::bitrixError());
        $this->flush();

        // Inventory management counts the product by its warehouses: the 5 units of the document.
        $after = (int) ProductTable::getRow(['select' => ['QUANTITY'], 'filter' => ['=ID' => $product]])['QUANTITY'];
        self::assertNotSame($quantity, $after);
        self::assertSame($after, $this->uploaded($product)['stock']);
    }

    private static function bitrixError(): string
    {
        $exception = $GLOBALS['APPLICATION']->GetException();

        return $exception ? (string) $exception->GetString() : '';
    }
}
