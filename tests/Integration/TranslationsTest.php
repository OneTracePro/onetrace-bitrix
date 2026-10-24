<?php

declare(strict_types=1);

namespace Onetrace\Connector\Tests\Integration;

use Bitrix\Main\SiteTable;
use Onetrace\Connector\Catalog;
use Onetrace\Connector\Config;
use Onetrace\Connector\Tests\TestCase;

/**
 * Catalog languages: a site of the same project in another language with its copy of the catalog (the same XML_ID,
 * as the exchange with 1C makes it).
 */
final class TranslationsTest extends TestCase
{
    private const SECOND = 'o2';

    protected function setUp(): void
    {
        parent::setUp();

        if (!SiteTable::getRow(['select' => ['LID'], 'filter' => ['=LID' => self::SECOND]])) {
            $culture = \Bitrix\Main\Localization\CultureTable::getRow(['select' => ['ID'], 'filter' => ['=CODE' => 'en']])
                ?? \Bitrix\Main\Localization\CultureTable::getRow(['select' => ['ID'], 'order' => ['ID' => 'ASC']]);
            $site = new \CSite();
            self::assertTrue((bool) $site->Add([
                'LID' => self::SECOND, 'ACTIVE' => 'Y', 'SORT' => 900, 'DEF' => 'N', 'NAME' => 'English', 'DIR' => '/en/', 'LANGUAGE_ID' => 'en',
                'CULTURE_ID' => (int) $culture['ID'], 'DOC_ROOT' => '', 'DOMAINS' => '',
            ]), (string) $site->LAST_ERROR);
        }

        foreach (['url' => 'https://cdp.example.com', 'write_key' => 'cdp_wk_test', 'secret_key' => 'cdp_sk_test', 'part_catalog' => 'Y'] as $name => $value) {
            Config::set($name, $value, self::SECOND);
        }
    }

    protected function tearDown(): void
    {
        Config::set('secret_key', '', self::SECOND);
        parent::tearDown();
    }

    private static function englishIblock(): int
    {
        $existing = \CIBlock::GetList([], ['CODE' => 'onetrace_test_en', 'CHECK_PERMISSIONS' => 'N'])->Fetch();

        if (is_array($existing)) {
            return (int) $existing['ID'];
        }

        $id = (int) (new \CIBlock())->Add([
            'IBLOCK_TYPE_ID' => 'onetrace_test', 'CODE' => 'onetrace_test_en', 'NAME' => 'Catalog EN', 'LID' => [self::SECOND], 'ACTIVE' => 'Y',
            'DETAIL_PAGE_URL' => '#SITE_DIR#/catalog/#ELEMENT_CODE#/', 'GROUP_ID' => ['2' => 'R'],
        ]);
        \CCatalog::Add(['IBLOCK_ID' => $id]);

        return $id;
    }

    public function testUploadsTheNamesLinksAndSectionsOfTheOtherLanguageSite(): void
    {
        $xml = 'onetrace-' . bin2hex(random_bytes(4));
        $section = $this->section('Обувь');
        (new \CIBlockSection())->Update($section, ['XML_ID' => $xml . '-shoes']);
        $englishSection = (int) (new \CIBlockSection())->Add(['IBLOCK_ID' => self::englishIblock(), 'NAME' => 'Shoes', 'XML_ID' => $xml . '-shoes', 'ACTIVE' => 'Y', 'CODE' => $xml . '-shoes']);
        (new \CIBlockElement())->Add(['IBLOCK_ID' => self::englishIblock(), 'NAME' => 'Sneakers', 'XML_ID' => $xml, 'CODE' => $xml, 'ACTIVE' => 'Y', 'IBLOCK_SECTION_ID' => $englishSection]);
        $product = $this->product('Кроссовки', 49.0, $section);
        (new \CIBlockElement())->Update($product, ['XML_ID' => $xml]);
        $this->flush();

        $uploads = array_values(array_filter($this->platform->requests, static fn (array $r): bool => $r['method'] === 'POST' && $r['path'] === '/api/v1/products'));
        $body = end($uploads)['body'];
        $item = $body['items'][0];

        self::assertSame((string) $product, $item['id']);
        self::assertSame('Кроссовки', $item['name'], 'The first site gives the main language.');
        self::assertSame('Sneakers', $item['translations']['en']['name']);
        self::assertStringContainsString('/en/catalog/' . $xml . '/', $item['translations']['en']['url']);
        self::assertContains(['id' => (string) $section, 'name' => 'Обувь', 'translations' => ['en' => ['name' => 'Shoes']]], $body['categories']);
        self::assertSame(1, count(array_filter($this->platform->requests, static fn (array $r): bool => $r['path'] === '/api/v1/products' && $r['method'] === 'POST' && in_array((string) $product, array_column($r['body']['items'], 'id'), true))), 'One upload: the second site of the project does not overwrite the products.');
    }

    public function testAChangedCopyUploadsTheMainProduct(): void
    {
        $xml = 'onetrace-' . bin2hex(random_bytes(4));
        $product = $this->product('Рюкзак', 70.0);
        (new \CIBlockElement())->Update($product, ['XML_ID' => $xml]);
        $copy = (int) (new \CIBlockElement())->Add(['IBLOCK_ID' => self::englishIblock(), 'NAME' => 'Backpack', 'XML_ID' => $xml, 'CODE' => $xml, 'ACTIVE' => 'Y']);
        $this->flush();
        $this->platform->requests = [];

        (new \CIBlockElement())->Update($copy, ['NAME' => 'Daypack']);
        $this->flush();

        $upload = end($this->platform->requests);
        self::assertSame('/api/v1/products', $upload['path']);
        self::assertSame([(string) $product], array_column($upload['body']['items'], 'id'));
        self::assertSame('Daypack', $upload['body']['items'][0]['translations']['en']['name']);
    }

    public function testUploadsTheDescriptionOfTheCopy(): void
    {
        $xml = 'onetrace-' . bin2hex(random_bytes(4));
        $same = 'onetrace-' . bin2hex(random_bytes(4));
        $product = $this->product('Куртка', 150.0);
        $twin = $this->product('Шапка', 20.0);
        (new \CIBlockElement())->Update($product, ['XML_ID' => $xml, 'DETAIL_TEXT' => '<p>Тёплая куртка</p>', 'DETAIL_TEXT_TYPE' => 'html']);
        (new \CIBlockElement())->Update($twin, ['XML_ID' => $same, 'PREVIEW_TEXT' => 'Wool', 'PREVIEW_TEXT_TYPE' => 'text']);
        (new \CIBlockElement())->Add(['IBLOCK_ID' => self::englishIblock(), 'NAME' => 'Jacket', 'XML_ID' => $xml, 'CODE' => $xml, 'ACTIVE' => 'Y', 'DETAIL_TEXT' => "Warm jacket\nWindproof", 'DETAIL_TEXT_TYPE' => 'text']);
        (new \CIBlockElement())->Add(['IBLOCK_ID' => self::englishIblock(), 'NAME' => 'Hat', 'XML_ID' => $same, 'CODE' => $same, 'ACTIVE' => 'Y', 'DETAIL_TEXT' => '<p>Wool</p>', 'DETAIL_TEXT_TYPE' => 'html']);
        $this->flush();

        self::assertSame('Тёплая куртка', $this->uploaded($product)['description']);
        self::assertSame("Warm jacket\nWindproof", $this->uploaded($product)['translations']['en']['description']);
        self::assertArrayNotHasKey('description', $this->uploaded($twin)['translations']['en'], 'The same text is not a translation.');
    }

    public function testAskAboutACopyGivesTheMainProduct(): void
    {
        $xml = 'onetrace-' . bin2hex(random_bytes(4));
        $product = $this->product('Зонт', 30.0);
        (new \CIBlockElement())->Update($product, ['XML_ID' => $xml]);
        $copy = (int) (new \CIBlockElement())->Add(['IBLOCK_ID' => self::englishIblock(), 'NAME' => 'Umbrella', 'XML_ID' => $xml, 'CODE' => $xml, 'ACTIVE' => 'Y']);
        \Bitrix\Catalog\Model\Product::add(['ID' => $copy, 'QUANTITY' => 3]);

        self::assertSame($product, Catalog::platformId($copy));
        self::assertTrue(Config::translationSite(self::SECOND), 'Its own ids are unknown to the platform: no automatic place.');
        self::assertFalse(Config::translationSite(self::SITE));
    }
}
