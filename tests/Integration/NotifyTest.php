<?php

declare(strict_types=1);

namespace Onetrace\Connector\Tests\Integration;

use Bitrix\Catalog\Model\Product;
use Onetrace\Connector\Config;
use Onetrace\Connector\Tests\TestCase;

/**
 * The component onetrace:catalog.notify: the place of the platform's "Notify me when available" form on the page of a
 * product that cannot be bought.
 */
final class NotifyTest extends TestCase
{
    /**
     * @param array<string, mixed> $params
     */
    private function render(array $params): string
    {
        global $APPLICATION;

        ob_start();
        $APPLICATION->IncludeComponent('onetrace:catalog.notify', '', $params);

        return trim((string) ob_get_clean());
    }

    private function soldOut(int $productId): void
    {
        self::assertTrue(Product::update($productId, ['QUANTITY' => 0, 'QUANTITY_TRACE' => 'Y', 'CAN_BUY_ZERO' => 'N'])->isSuccess());
    }

    public function testThePlaceOfTheFormForAProductOutOfStock(): void
    {
        $product = $this->product('Raincoat', 70.0);
        self::assertSame('', $this->render(['PRODUCT_ID' => $product]), 'The product is in stock.');

        $this->soldOut($product);
        self::assertSame('<div data-cdp-notify="' . $product . '"></div>', $this->render(['PRODUCT_ID' => $product]));
    }

    public function testAnOfferGivesItsProduct(): void
    {
        $product = $this->product('Parka', 120.0);
        [$offer] = $this->offers($product, [[0, 'Y']]);

        self::assertSame('<div data-cdp-notify="' . $product . '"></div>', $this->render(['PRODUCT_ID' => $offer]));
    }

    public function testTheSettingAndTheTrackerTurnItOff(): void
    {
        $product = $this->product('Gloves', 20.0);
        $this->soldOut($product);

        Config::set('part_notify', 'N', self::SITE);
        self::assertSame('', $this->render(['PRODUCT_ID' => $product]));

        Config::set('part_notify', 'Y', self::SITE);
        Config::set('part_tracker', 'N', self::SITE);
        self::assertSame('', $this->render(['PRODUCT_ID' => $product]), 'The tracker draws the form.');
    }

    public function testNothingForElementsOutsideTheCatalog(): void
    {
        self::assertSame('', $this->render(['PRODUCT_ID' => '0" onmouseover="alert(1)']));
        self::assertSame('', $this->render(['PRODUCT_ID' => 99999999]));
    }
}
