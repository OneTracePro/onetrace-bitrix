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
 * offer prices, the stock — the sum of the offers in stock. Attributes are the properties of the smart filter.
 */
final class Catalog
{
    /** @var array<int, array{id: string, name: string, parent_id?: string}|null> */
    private static array $sections = [];

    /** @var array<int, int> section id => depth level */
    private static array $depths = [];

    /** @var array<int, string> section id => XML_ID */
    private static array $sectionXml = [];

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
     * The products of the uploaded catalogs for an element of a translation copy (a site in another language): the
     * elements with its XML_ID in the infoblocks of the catalog sites; the element itself when it is not a copy.
     *
     * @return list<int>
     */
    public static function mainProducts(int $elementId): array
    {
        $element = \CIBlockElement::GetList([], ['ID' => $elementId], false, false, ['ID', 'IBLOCK_ID', 'XML_ID'])->Fetch();

        if (!is_array($element)) {
            return [$elementId];
        }

        $main = [];

        foreach (Config::catalogSites() as $siteId) {
            $main = array_merge($main, self::iblocks($siteId));
        }

        if (in_array((int) $element['IBLOCK_ID'], $main, true) || (string) $element['XML_ID'] === '' || $main === []) {
            return [$elementId];
        }

        $ids = [];
        $rows = \CIBlockElement::GetList([], ['IBLOCK_ID' => $main, 'XML_ID' => (string) $element['XML_ID']], false, false, ['ID']);

        while ($row = $rows->Fetch()) {
            $ids[] = (int) $row['ID'];
        }

        return $ids;
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
        $sources = [];

        if ($iblocks !== [] && $productIds !== []) {
            $elements = \CIBlockElement::GetList([], ['ID' => $productIds, 'IBLOCK_ID' => $iblocks], false, false, [
                'ID', 'IBLOCK_ID', 'NAME', 'ACTIVE', 'DETAIL_PAGE_URL', 'DETAIL_PICTURE', 'PREVIEW_PICTURE', 'IBLOCK_SECTION_ID', 'XML_ID',
            ]);
            $base = rtrim(Connection::siteUrl($siteId), '/');

            while ($element = $elements->GetNextElement()) {
                $fields = $element->GetFields();
                $id = (int) $fields['ID'];
                $found[] = $id;
                $categories = self::categoryIds($id);
                $categoryIds = array_merge($categoryIds, $categories);
                $properties = $element->GetProperties();
                $items[] = self::item($siteId, $fields, $properties, $categories, $base);
                $sources[] = ['xml' => (string) $fields['XML_ID'], 'labels' => array_map(static fn (array $attribute): string => $attribute[0], self::attributes((int) $fields['IBLOCK_ID'], $properties)[1])];
            }
        }

        $missing = array_values(array_map('strval', array_diff($productIds, $found)));
        $categories = self::categories(array_unique($categoryIds));

        foreach (Config::translationSites($siteId) as $language => $translationSiteId) {
            [$items, $categories] = self::translate($translationSiteId, $language, $items, $sources, $categories);
        }

        return [$items, $categories, $missing];
    }

    /**
     * Translations from a site of the project in another language: its copies of the products and sections (the same
     * XML_ID) give the name, the link of that site and the values of the attributes (under the labels of the main site).
     *
     * @param list<array<string, mixed>> $items
     * @param list<array{xml: string, labels: array<string, string>}> $sources the main XML_ID and attribute labels by code
     * @param list<array<string, mixed>> $categories
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private static function translate(string $siteId, string $language, array $items, array $sources, array $categories): array
    {
        $iblocks = self::iblocks($siteId);
        $xmlIds = array_values(array_filter(array_column($sources, 'xml')));

        if ($iblocks === [] || $xmlIds === []) {
            return [$items, $categories];
        }

        $copies = [];
        $base = rtrim(Connection::siteUrl($siteId), '/');
        $elements = \CIBlockElement::GetList([], ['XML_ID' => $xmlIds, 'IBLOCK_ID' => $iblocks, 'ACTIVE' => 'Y'], false, false, ['ID', 'IBLOCK_ID', 'NAME', 'DETAIL_PAGE_URL', 'XML_ID']);

        while ($element = $elements->GetNextElement()) {
            $fields = $element->GetFields();
            $copies[(string) $fields['XML_ID']] ??= [
                'name' => html_entity_decode((string) $fields['~NAME']),
                'url' => (string) $fields['~DETAIL_PAGE_URL'] !== '' ? self::absolute((string) $fields['~DETAIL_PAGE_URL'], $base) : null,
                'values' => array_map(static fn (array $attribute): string => $attribute[1], self::attributes((int) $fields['IBLOCK_ID'], $element->GetProperties())[1]),
            ];
        }

        foreach ($items as $index => $item) {
            $copy = $copies[$sources[$index]['xml']] ?? null;

            if ($copy === null || $sources[$index]['xml'] === '') {
                continue;
            }

            $params = [];

            foreach ($sources[$index]['labels'] as $code => $label) {
                if (isset($copy['values'][$code]) && $copy['values'][$code] !== ($item['params'][$label] ?? null)) {
                    $params[$label] = $copy['values'][$code];
                }
            }

            $items[$index] = CatalogItem::translate($item, $language, $copy['name'] !== $item['name'] ? $copy['name'] : null, $copy['url'] !== ($item['url'] ?? null) ? $copy['url'] : null, $params);
        }

        $sectionXml = [];

        foreach ($categories as $category) {
            $xml = (string) (self::$sectionXml[(int) $category['id']] ?? '');

            if ($xml !== '') {
                $sectionXml[$xml] = true;
            }
        }

        if ($sectionXml !== []) {
            $names = [];
            $sections = \CIBlockSection::GetList([], ['IBLOCK_ID' => $iblocks, 'XML_ID' => array_keys($sectionXml)], false, ['ID', 'NAME', 'XML_ID']);

            while ($section = $sections->Fetch()) {
                $names[(string) $section['XML_ID']] ??= html_entity_decode((string) $section['NAME']);
            }

            foreach ($categories as $index => $category) {
                $name = $names[(string) (self::$sectionXml[(int) $category['id']] ?? '')] ?? null;

                if ($name !== null && $name !== $category['name']) {
                    $categories[$index] = CatalogItem::translateCategory($category, $language, $name);
                }
            }
        }

        return [$items, $categories];
    }

    /**
     * The product of the catalog for an element (an offer gives its parent) and whether it can be bought: active and
     * available (in stock, or tracing and "buy at zero" let it be sold) — the same "available" as in the catalog; null —
     * not a product of the trade catalog.
     *
     * @return array{0: int, 1: bool}|null
     */
    public static function availability(int $elementId): ?array
    {
        if ($elementId <= 0 || !self::isCatalogElement($elementId)) {
            return null;
        }

        $productId = self::catalogIds($elementId)[0];
        $element = \CIBlockElement::GetList([], ['ID' => $productId, 'CHECK_PERMISSIONS' => 'N'], false, false, ['ID', 'ACTIVE'])->Fetch();
        $product = ProductTable::getRow(['select' => ['AVAILABLE'], 'filter' => ['=ID' => $productId]]);

        if (!is_array($element) || $product === null) {
            return null;
        }

        return [$productId, $element['ACTIVE'] === 'Y' && $product['AVAILABLE'] === ProductTable::STATUS_YES];
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
        $offers = self::offerIds($id);
        [$price, $oldPrice, $currency] = self::prices($siteId, $id, $offers);
        $product = ProductTable::getRow(['select' => ['AVAILABLE', 'TYPE', 'QUANTITY', 'QUANTITY_TRACE', 'CAN_BUY_ZERO'], 'filter' => ['=ID' => $id]]);
        $picture = (int) ($fields['DETAIL_PICTURE'] ?: $fields['PREVIEW_PICTURE']);
        $image = $picture > 0 ? (string) \CFile::GetPath($picture) : '';
        $url = (string) $fields['~DETAIL_PAGE_URL'];
        [$brand, $attributes] = self::attributes((int) $fields['IBLOCK_ID'], $properties);
        $params = array_column($attributes, 1, 0);

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
            $params,
            self::stock($product, $offers)
        );
    }

    /**
     * Units in stock when the store keeps count: quantity tracing of the product is on and buying at zero is off (their
     * own settings or the defaults of the catalog module — the fetch of QUANTITY_TRACE and CAN_BUY_ZERO resolves
     * "default"). Zero stock of a product sold at zero does not mean "not available", so it goes without the stock. A
     * product with offers — the sum of the offers in stock, when every active offer keeps count and none is sold at
     * zero; null — not tracked or unknown.
     *
     * @param array<string, mixed>|null $product the row of b_catalog_product: TYPE, QUANTITY, QUANTITY_TRACE, CAN_BUY_ZERO
     * @param list<int> $offers active offers of the product
     */
    public static function stock(?array $product, array $offers): ?int
    {
        if ($product === null || (int) $product['TYPE'] === ProductTable::TYPE_SERVICE) {
            return null;
        }

        if ($offers === [] && (int) $product['TYPE'] !== ProductTable::TYPE_SKU) {
            return self::keepsCount($product) ? max(0, (int) floor((float) $product['QUANTITY'])) : null;
        }

        $total = 0;
        $rows = $offers !== [] ? ProductTable::getList(['select' => ['ID', 'QUANTITY', 'QUANTITY_TRACE', 'CAN_BUY_ZERO'], 'filter' => ['@ID' => $offers]])->fetchAll() : [];

        // No offers yet, or an offer without its catalog row: the total is unknown.
        if (count($rows) !== count($offers) || $rows === []) {
            return null;
        }

        foreach ($rows as $row) {
            // An offer without quantity tracing or sold at zero is always available: the total is unknown too.
            if (!self::keepsCount($row)) {
                return null;
            }

            $total += max(0, (int) floor((float) $row['QUANTITY']));
        }

        return $total;
    }

    /**
     * Quantity tracing on and buying at zero off: zero stock means "not available".
     *
     * @param array<string, mixed> $row QUANTITY_TRACE and CAN_BUY_ZERO of b_catalog_product, "default" resolved
     */
    private static function keepsCount(array $row): bool
    {
        return ($row['QUANTITY_TRACE'] ?? 'N') === ProductTable::STATUS_YES && ($row['CAN_BUY_ZERO'] ?? 'N') !== ProductTable::STATUS_YES;
    }

    /**
     * Active offers of a product; empty for a product without offers.
     *
     * @return list<int>
     */
    private static function offerIds(int $productId): array
    {
        $offers = \CCatalogSku::getOffersList([$productId], 0, ['ACTIVE' => 'Y'], ['ID']);

        return is_array($offers) && isset($offers[$productId]) ? array_map('intval', array_keys($offers[$productId])) : [];
    }

    /**
     * The price a guest pays with discounts, the price before discounts if higher, and the currency; a product with
     * offers — the lowest offer price.
     *
     * @param list<int>|null $offers active offers of the product, null — read them
     *
     * @return array{0: float|null, 1: float|null, 2: string}
     */
    private static function prices(string $siteId, int $productId, ?array $offers = null): array
    {
        $offers ??= self::offerIds($productId);
        $ids = $offers !== [] ? $offers : [$productId];

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
     * Brand (property BRAND, BRAND_REF or MANUFACTURER) and the values of the smart filter properties by code:
     * "COLOR" => ["Color", "White, Black"].
     *
     * @param array<string, array<string, mixed>> $properties
     *
     * @return array{0: string|null, 1: array<string, array{0: string, 1: string}>}
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
                $params[(string) $code] = [(string) $property['NAME'], $value];
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
            $chain = \CIBlockSection::GetNavChain(false, $id, ['ID', 'NAME', 'IBLOCK_SECTION_ID', 'XML_ID'], true);

            foreach ($chain as $section) {
                $sectionId = (int) $section['ID'];
                self::$sectionXml[$sectionId] = (string) $section['XML_ID'];
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
