<?php

declare(strict_types=1);

namespace Onetrace\Connector;

use Bitrix\Catalog\CatalogIblockTable;
use Bitrix\Catalog\ProductTable;
use Bitrix\Iblock\SectionPropertyTable;
use Bitrix\Main\Loader;
use OneTrace\Commerce\CatalogItem;

/**
 * Product catalog of a site on the platform: elements of the trade catalog infoblocks of the site. Trade offers
 * (SKU) are not separate products: an offer uploads its parent, prices of a product with offers are the lowest
 * offer prices. Attributes are the properties of the smart filter.
 */
final class Catalog
{
    /** @var array<int, array{id: string, name: string, parent_id?: string}|null> */
    private static array $sections = [];

    /** @var array<int, int> section id => depth level */
    private static array $depths = [];

    /**
     * Product infoblocks (not offers) of the trade catalog bound to a site.
     *
     * @return list<int>
     */
    public static function iblocks(string $siteId): array
    {
        Loader::includeModule('catalog');
        $ids = [];
        $rows = CatalogIblockTable::getList(['select' => ['IBLOCK_ID'], 'filter' => ['=PRODUCT_IBLOCK_ID' => 0]]);

        while ($row = $rows->fetch()) {
            $iblockId = (int) $row['IBLOCK_ID'];
            $sites = \CIBlock::GetSite($iblockId);

            while ($site = $sites->Fetch()) {
                if ((string) $site['SITE_ID'] === $siteId) {
                    $ids[] = $iblockId;
                }
            }
        }

        return $ids;
    }

    /**
     * The element belongs to an infoblock of the trade catalog (products or offers).
     */
    public static function isCatalogElement(int $elementId): bool
    {
        Loader::includeModule('iblock');
        Loader::includeModule('catalog');
        $iblockId = (int) \CIBlockElement::GetIBlockByID($elementId);

        return $iblockId > 0 && CatalogIblockTable::getRow(['select' => ['IBLOCK_ID'], 'filter' => ['=IBLOCK_ID' => $iblockId]]) !== null;
    }

    /**
     * Ids of the products to upload for a changed element: itself in a product infoblock, its parent for an offer.
     *
     * @return list<int>
     */
    public static function catalogIds(int $elementId): array
    {
        Loader::includeModule('catalog');
        $parent = \CCatalogSku::GetProductInfo($elementId);

        return is_array($parent) && (int) ($parent['ID'] ?? 0) > 0 ? [(int) $parent['ID']] : [$elementId];
    }

    /**
     * The product and the offer of a basket or order line: [product id, offer id or null].
     *
     * @return array{0: int, 1: int|null}
     */
    public static function productOf(int $elementId): array
    {
        $parent = self::catalogIds($elementId)[0];

        return $parent !== $elementId ? [$parent, $elementId] : [$elementId, null];
    }

    /**
     * Catalog items of a site, the categories with their ancestors, and the ids of products that are gone.
     *
     * @param list<int> $productIds
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<string>}
     */
    public static function items(string $siteId, array $productIds): array
    {
        Loader::includeModule('iblock');
        Loader::includeModule('catalog');
        $iblocks = self::iblocks($siteId);
        $items = [];
        $categoryIds = [];
        $found = [];

        if ($iblocks !== [] && $productIds !== []) {
            $elements = \CIBlockElement::GetList([], ['ID' => $productIds, 'IBLOCK_ID' => $iblocks], false, false, [
                'ID', 'IBLOCK_ID', 'NAME', 'ACTIVE', 'DETAIL_PAGE_URL', 'DETAIL_PICTURE', 'PREVIEW_PICTURE', 'IBLOCK_SECTION_ID',
            ]);
            $base = rtrim(Connection::siteUrl($siteId), '/');

            while ($element = $elements->GetNextElement()) {
                $fields = $element->GetFields();
                $id = (int) $fields['ID'];
                $found[] = $id;
                $categories = self::categoryIds($id);
                $categoryIds = array_merge($categoryIds, $categories);
                $items[] = self::item($siteId, $fields, $element->GetProperties(), $categories, $base);
            }
        }

        $missing = array_values(array_map('strval', array_diff($productIds, $found)));

        return [$items, self::categories(array_unique($categoryIds)), $missing];
    }

    /**
     * Name, price and category of a product for product events.
     *
     * @return array{name: string, price: float, currency: string, category: string|null}|null
     */
    public static function brief(string $siteId, int $productId): ?array
    {
        Loader::includeModule('iblock');
        Loader::includeModule('catalog');
        $element = \CIBlockElement::GetList([], ['ID' => $productId], false, false, ['ID', 'NAME'])->Fetch();

        if (!is_array($element)) {
            return null;
        }

        [$price, , $currency] = self::prices($siteId, $productId);
        $categories = self::categoryIds($productId);

        return ['name' => (string) $element['NAME'], 'price' => (float) $price, 'currency' => $currency, 'category' => $categories !== [] ? (string) $categories[0] : null];
    }

    /**
     * Deepest section of a product — category_id of events.
     */
    public static function categoryId(int $productId): ?string
    {
        $categories = self::categoryIds($productId);

        return $categories !== [] ? (string) $categories[0] : null;
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, array<string, mixed>> $properties
     * @param list<int> $categories the deepest first
     *
     * @return array<string, mixed>
     */
    private static function item(string $siteId, array $fields, array $properties, array $categories, string $base): array
    {
        $id = (int) $fields['ID'];
        [$price, $oldPrice, $currency] = self::prices($siteId, $id);
        $product = ProductTable::getRow(['select' => ['AVAILABLE'], 'filter' => ['=ID' => $id]]);
        $picture = (int) ($fields['DETAIL_PICTURE'] ?: $fields['PREVIEW_PICTURE']);
        $image = $picture > 0 ? (string) \CFile::GetPath($picture) : '';
        $url = (string) $fields['~DETAIL_PAGE_URL'];
        [$brand, $params] = self::attributes((int) $fields['IBLOCK_ID'], $properties);

        return CatalogItem::make(
            $id,
            html_entity_decode((string) $fields['~NAME']),
            $url !== '' ? self::absolute($url, $base) : null,
            $image !== '' ? self::absolute($image, $base) : null,
            $price,
            $currency,
            $fields['ACTIVE'] === 'Y' && ($product['AVAILABLE'] ?? 'N') === 'Y',
            $categories,
            $oldPrice,
            $brand,
            $params
        );
    }

    /**
     * The price a guest pays with discounts, the price before discounts if higher, and the currency; a product with
     * offers — the lowest offer price.
     *
     * @return array{0: float|null, 1: float|null, 2: string}
     */
    private static function prices(string $siteId, int $productId): array
    {
        $ids = [$productId];
        $offers = \CCatalogSku::getOffersList([$productId], 0, ['ACTIVE' => 'Y'], ['ID']);

        if (is_array($offers) && isset($offers[$productId]) && $offers[$productId] !== []) {
            $ids = array_map('intval', array_keys($offers[$productId]));
        }

        $best = null;

        foreach ($ids as $id) {
            $optimal = \CCatalogProduct::GetOptimalPrice($id, 1, [2], 'N', [], $siteId);
            $result = is_array($optimal) ? ($optimal['RESULT_PRICE'] ?? null) : null;

            if (is_array($result) && ($best === null || (float) $result['DISCOUNT_PRICE'] < (float) $best['DISCOUNT_PRICE'])) {
                $best = $result;
            }
        }

        if ($best === null) {
            // Guests cannot see any price group: the base price as it is.
            $base = \Bitrix\Catalog\PriceTable::getRow([
                'select' => ['PRICE', 'CURRENCY'],
                'filter' => ['@PRODUCT_ID' => $ids, '=CATALOG_GROUP.BASE' => 'Y'],
                'order' => ['PRICE' => 'ASC'],
            ]);

            return $base !== null
                ? [round((float) $base['PRICE'], 2), null, (string) $base['CURRENCY']]
                : [null, null, (string) \Bitrix\Currency\CurrencyManager::getBaseCurrency()];
        }

        $final = round((float) $best['DISCOUNT_PRICE'], 2);
        $base = round((float) $best['BASE_PRICE'], 2);

        return [$final, $base > $final ? $base : null, (string) $best['CURRENCY']];
    }

    /**
     * Brand (property BRAND, BRAND_REF or MANUFACTURER) and the values of the smart filter properties:
     * "Color" => "White, Black".
     *
     * @param array<string, array<string, mixed>> $properties
     *
     * @return array{0: string|null, 1: array<string, string>}
     */
    private static function attributes(int $iblockId, array $properties): array
    {
        static $filters = [];
        $filters[$iblockId] ??= array_map('intval', array_column(SectionPropertyTable::getList([
            'select' => ['PROPERTY_ID'], 'filter' => ['=IBLOCK_ID' => $iblockId, '=SMART_FILTER' => 'Y'], 'group' => ['PROPERTY_ID'],
        ])->fetchAll(), 'PROPERTY_ID'));
        $brand = null;
        $params = [];

        foreach ($properties as $code => $property) {
            $isBrand = in_array(strtoupper((string) $code), ['BRAND', 'BRAND_REF', 'MANUFACTURER'], true);

            if (!$isBrand && !in_array((int) $property['ID'], $filters[$iblockId], true)) {
                continue;
            }

            if ($property['VALUE'] === false || $property['VALUE'] === '' || $property['VALUE'] === [] || $property['VALUE'] === null) {
                continue;
            }

            $display = \CIBlockFormatProperties::GetDisplayValue(['ID' => 0, 'NAME' => ''], $property, '');
            $value = $display['DISPLAY_VALUE'] ?? '';
            $value = trim(strip_tags(html_entity_decode(is_array($value) ? implode(', ', $value) : (string) $value)));

            if ($value === '') {
                continue;
            }

            if ($isBrand) {
                $brand ??= $value;
            } else {
                $params[(string) $property['NAME']] = $value;
            }
        }

        return [$brand, $params];
    }

    /**
     * Active sections of a product, the deepest first.
     *
     * @return list<int>
     */
    private static function categoryIds(int $productId): array
    {
        $ids = [];
        $groups = \CIBlockElement::GetElementGroups($productId, true, ['ID', 'DEPTH_LEVEL', 'ACTIVE']);

        while ($group = $groups->Fetch()) {
            if ($group['ACTIVE'] === 'Y') {
                $ids[] = (int) $group['ID'];
                self::$depths[(int) $group['ID']] = (int) $group['DEPTH_LEVEL'];
            }
        }

        usort($ids, static fn (int $a, int $b): int => self::$depths[$b] <=> self::$depths[$a]);

        return $ids;
    }

    /**
     * Sections with their ancestors.
     *
     * @param array<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    private static function categories(array $ids): array
    {
        $all = [];

        foreach ($ids as $id) {
            $chain = \CIBlockSection::GetNavChain(false, $id, ['ID', 'NAME', 'IBLOCK_SECTION_ID'], true);

            foreach ($chain as $section) {
                $sectionId = (int) $section['ID'];
                self::$sections[$sectionId] ??= CatalogItem::category($sectionId, html_entity_decode((string) $section['NAME']), (int) $section['IBLOCK_SECTION_ID'] ?: null);
                $all[$sectionId] = self::$sections[$sectionId];
            }
        }

        return array_values(array_filter($all));
    }

    private static function absolute(string $path, string $base): string
    {
        return preg_match('#^https?://#i', $path) === 1 ? $path : $base . '/' . ltrim($path, '/');
    }
}
