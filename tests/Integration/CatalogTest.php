<?php

declare(strict_types=1);

namespace Onetrace\Connector\Tests\Integration;

use Onetrace\Connector\Tests\TestCase;

final class CatalogTest extends TestCase
{
    public function testUploadsAProductWithItsSectionsAndPriceAndDeletesIt(): void
    {
        $parent = $this->section('Shoes');
        $child = $this->section('Sneakers', $parent);
        $product = $this->product('Runner', 59.0, $child);
        $this->flush();

        $uploads = array_values(array_filter($this->platform->requests, static fn (array $r): bool => $r['method'] === 'POST' && $r['path'] === '/api/v1/products'));
        $body = end($uploads)['body'];
        $item = $body['items'][0];

        self::assertSame((string) $product, $item['id']);
        self::assertSame('Runner', $item['name']);
        self::assertSame(59.0, $item['price']);
        self::assertSame('RUB', $item['currency']);
        self::assertTrue($item['available']);
        self::assertSame([(string) $child], $item['category_ids']);
        self::assertStringStartsWith('http', $item['url']);
        self::assertContains(['id' => (string) $child, 'name' => 'Sneakers', 'parent_id' => (string) $parent], $body['categories']);
        self::assertContains(['id' => (string) $parent, 'name' => 'Shoes'], $body['categories']);

        \CIBlockElement::Delete($product);
        $this->flush();

        $last = end($this->platform->requests);
        self::assertSame(['DELETE', ['ids' => [(string) $product]]], [$last['method'], $last['body']]);
    }

    public function testIgnoresElementsOutsideTheCatalog(): void
    {
        $news = \CIBlock::GetList([], ['CODE' => 'onetrace_news', 'CHECK_PERMISSIONS' => 'N'])->Fetch();
        $iblockId = is_array($news) ? (int) $news['ID'] : (int) (new \CIBlock())->Add(['IBLOCK_TYPE_ID' => 'onetrace_test', 'CODE' => 'onetrace_news', 'NAME' => 'News', 'LID' => [self::SITE], 'ACTIVE' => 'Y']);
        (new \CIBlockElement())->Add(['IBLOCK_ID' => $iblockId, 'NAME' => 'News', 'ACTIVE' => 'Y']);
        $this->flush();

        self::assertSame([], $this->platform->requests);
    }

    public function testAnOfferUploadsItsProduct(): void
    {
        $product = $this->product('Hoodie', 30.0);
        $this->flush();
        $this->platform->requests = [];

        \Bitrix\Catalog\Model\Product::update($product, ['QUANTITY' => 0, 'AVAILABLE' => 'N']);
        $this->flush();

        $upload = end($this->platform->requests);
        self::assertSame('/api/v1/products', $upload['path']);
        self::assertSame((string) $product, $upload['body']['items'][0]['id']);
    }

    public function testUploadsTheDescriptionAsText(): void
    {
        $product = $this->product('Trail runner', 80.0);
        self::assertTrue((new \CIBlockElement())->Update($product, [
            'DETAIL_TEXT' => '<p>Light <b>trail</b> shoes&nbsp;for&nbsp;rocks</p><ul><li>Mesh upper</li><li>Grip sole</li></ul>', 'DETAIL_TEXT_TYPE' => 'html',
            'PREVIEW_TEXT' => 'Trail shoes', 'PREVIEW_TEXT_TYPE' => 'text',
        ]));
        $this->flush();

        self::assertSame("Light trail shoes for rocks\n• Mesh upper\n• Grip sole", $this->uploaded($product)['description'] ?? null);
    }

    public function testATextDescriptionKeepsItsCharactersAndLines(): void
    {
        $product = $this->product('Bottle', 12.0);
        self::assertTrue((new \CIBlockElement())->Update($product, ['DETAIL_TEXT' => "Height < 25 cm & 0.5 l\r\nDishwasher safe <yes>", 'DETAIL_TEXT_TYPE' => 'text']));
        $this->flush();

        self::assertSame("Height < 25 cm & 0.5 l\nDishwasher safe <yes>", $this->uploaded($product)['description'] ?? null);
    }

    public function testThePreviewTextWithoutTheDetailText(): void
    {
        $product = $this->product('Cap', 15.0);
        self::assertTrue((new \CIBlockElement())->Update($product, ['DETAIL_TEXT' => '<p>&nbsp;</p>', 'DETAIL_TEXT_TYPE' => 'html', 'PREVIEW_TEXT' => '<p>Cotton cap</p>', 'PREVIEW_TEXT_TYPE' => 'html']));
        $this->flush();

        self::assertSame('Cotton cap', $this->uploaded($product)['description'] ?? null);

        $plain = $this->product('Scarf', 18.0);
        $this->flush();

        self::assertArrayNotHasKey('description', (array) $this->uploaded($plain), 'No texts — no description.');
    }
}
