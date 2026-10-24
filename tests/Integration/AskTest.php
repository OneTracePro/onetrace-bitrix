<?php

declare(strict_types=1);

namespace Onetrace\Connector\Tests\Integration;

use Onetrace\Connector\Config;
use Onetrace\Connector\Tests\TestCase;

/**
 * The component onetrace:product.ask: the place of the platform's "Ask AI about this product" widget on the product page.
 */
final class AskTest extends TestCase
{
    /**
     * @param array<string, mixed> $params
     */
    private function render(array $params): string
    {
        global $APPLICATION;

        ob_start();
        $APPLICATION->IncludeComponent('onetrace:product.ask', '', $params);

        return trim((string) ob_get_clean());
    }

    public function testThePlaceOfTheWidgetWithTheProductId(): void
    {
        $product = $this->product('Backpack', 90.0);

        self::assertSame('<div data-cdp-ask data-product-id="' . $product . '"></div>', $this->render(['PRODUCT_ID' => $product]));
    }

    public function testAnOfferGivesItsProduct(): void
    {
        $product = $this->product('Jeans', 60.0);
        [$offer] = $this->offers($product, [[3, 'Y']]);

        self::assertSame('<div data-cdp-ask data-product-id="' . $product . '"></div>', $this->render(['PRODUCT_ID' => $offer]));
    }

    public function testTheSettingAndTheTrackerTurnItOff(): void
    {
        $product = $this->product('Belt', 25.0);

        Config::set('part_ask', 'N', self::SITE);
        self::assertSame('', $this->render(['PRODUCT_ID' => $product]));

        Config::set('part_ask', 'Y', self::SITE);
        Config::set('part_tracker', 'N', self::SITE);
        self::assertSame('', $this->render(['PRODUCT_ID' => $product]), 'The tracker draws the widget.');
    }

    public function testOnByDefault(): void
    {
        \Bitrix\Main\Config\Option::delete(Config::MODULE, ['name' => 'part_ask', 'site_id' => self::SITE]);

        self::assertTrue(Config::ask(self::SITE));
    }

    public function testNothingForElementsOutsideTheCatalog(): void
    {
        self::assertSame('', $this->render(['PRODUCT_ID' => '0" onmouseover="alert(1)']));
        self::assertSame('', $this->render(['PRODUCT_ID' => 99999999]));
    }
}
