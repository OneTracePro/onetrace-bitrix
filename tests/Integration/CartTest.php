<?php

declare(strict_types=1);

namespace Onetrace\Connector\Tests\Integration;

use Bitrix\Sale\Basket;
use Bitrix\Sale\Fuser;
use Onetrace\Connector\Tests\TestCase;

final class CartTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->emptyBasket();
        $this->platform->requests = [];
    }

    private function basket(): Basket
    {
        return Basket::loadItemsForFUser(Fuser::getId(), self::SITE);
    }

    private function emptyBasket(): void
    {
        $basket = $this->basket();

        foreach ($basket as $item) {
            $item->delete();
        }

        $basket->save();
        \Onetrace\Connector\Queue::clear();
    }

    public function testCartChangesOfAVisitorWithTheTrackerCookie(): void
    {
        $product = $this->product('Linen Shirt', 25.0);
        $this->visitor('browser-3');

        $basket = $this->basket();
        $item = $basket->createItem('catalog', $product);
        $item->setFields(['QUANTITY' => 2, 'CURRENCY' => 'RUB', 'LID' => self::SITE, 'PRODUCT_PROVIDER_CLASS' => \Bitrix\Catalog\Product\CatalogProvider::class]);
        self::assertTrue($basket->save()->isSuccess());
        $item->setField('QUANTITY', 1);
        self::assertTrue($basket->save()->isSuccess());
        $item->delete();
        self::assertTrue($basket->save()->isSuccess());
        $this->flush();

        $added = $this->platform->events('add_to_cart');
        $removed = $this->platform->events('remove_from_cart');

        self::assertCount(1, $added);
        self::assertSame('browser-3', $added[0]['anonymousId']);
        self::assertSame(['product_id' => (string) $product, 'product_name' => 'Linen Shirt', 'price' => 25.0, 'quantity' => 2, 'currency' => 'RUB'], $added[0]['properties']);
        self::assertSame([1, 1], array_map(static fn (array $m): int => $m['properties']['quantity'], $removed), 'One less, then the last one.');
        self::assertContract($added[0]);
    }

    public function testNothingForAVisitorWhoCannotBeLinked(): void
    {
        $product = $this->product();
        $basket = $this->basket();
        $basket->createItem('catalog', $product)->setFields(['QUANTITY' => 1, 'CURRENCY' => 'RUB', 'LID' => self::SITE, 'PRODUCT_PROVIDER_CLASS' => \Bitrix\Catalog\Product\CatalogProvider::class]);
        $basket->save();
        $this->flush();

        self::assertSame([], $this->platform->events('add_to_cart'));

        $this->emptyBasket();
    }

    public function testTheCheckoutCountsOncePerCartContent(): void
    {
        $product = $this->product('Rain Jacket', 90.0);
        $this->visitor('browser-5');
        $basket = $this->basket();
        $basket->createItem('catalog', $product)->setFields(['QUANTITY' => 1, 'CURRENCY' => 'RUB', 'LID' => self::SITE, 'PRODUCT_PROVIDER_CLASS' => \Bitrix\Catalog\Product\CatalogProvider::class]);
        $basket->save();
        $result = [];

        // sale.order.ajax recalculates the form on every change: the event stays one.
        \Onetrace\Connector\Handler\Sale::onCheckout($result);
        \Onetrace\Connector\Handler\Sale::onCheckout($result);
        $this->flush();
        $checkout = $this->platform->events('checkout_started');

        self::assertCount(1, $checkout);
        self::assertSame(90.0, $checkout[0]['properties']['amount']);
        self::assertSame((string) $product, $checkout[0]['properties']['products'][0]['product_id']);
        self::assertContract($checkout[0]);

        $this->emptyBasket();
    }
}
