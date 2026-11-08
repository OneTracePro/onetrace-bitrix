<?php

declare(strict_types=1);

namespace Onetrace\Connector;

use Bitrix\Catalog\CatalogIblockTable;
use Bitrix\Catalog\ProductTable;
use Bitrix\Main\Application;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Loader;
use Bitrix\Main\SiteTable;

/**
 * Cart links of shoppers' AI assistants (the create_cart tool of OneTrace): ?onetrace_cart=ID:QTY,ID:QTY on any
 * public page of the site puts the products into the cart of the visitor (a guest too — by the fuser of the sale
 * module) and redirects to the cart page with the other parameters of the link (utm_* …), so the tracker of the
 * cart page records the visit with them. Nothing could be added — the same page without the parameter.
 *
 * Ids are the ids of the platform catalog: the element of a product, or of an offer — that offer; a product with
 * offers needs the choice of an offer and is skipped, as products that are unknown, inactive or cannot be bought.
 * A site in another language of the project takes its copy of the product (the same XML_ID).
 */
final class CartLink
{
    public const PARAM = 'onetrace_cart';

    public const MAX_PAIRS = 20;

    public const MAX_QUANTITY = 99;

    /**
     * A cart link: a GET request of a page with the parameter, not ajax (composite requests too), not a service or
     * a file of /bitrix/.
     */
    public static function requested(HttpRequest $request): bool
    {
        return $request->getQuery(self::PARAM) !== null
            && $request->getRequestMethod() === 'GET'
            && !$request->isAjaxRequest()
            && !\Bitrix\Main\Composite\Engine::isAjaxRequest()
            && !str_starts_with((string) $request->getRequestedPage(), '/bitrix/');
    }

    /**
     * The products of the link go to the cart, the visitor — to the cart page (no caching of the answer); the
     * request ends there.
     */
    public static function redirect(string $siteId, HttpRequest $request): void
    {
        $target = self::target($siteId, $request);

        if ($target !== null) {
            Application::getInstance()->getContext()->getResponse()->addHeader('Cache-Control', 'no-store, private');
            LocalRedirect($target, false, '302 Found');
        }
    }

    /**
     * Puts the products of the link into the cart of the visitor and gives the address to go to: the cart page with
     * the other parameters of the request, or the same page without the parameter when nothing was added. Null — no
     * cart link, or the site is not connected to OneTrace.
     */
    public static function target(string $siteId, HttpRequest $request): ?string
    {
        $value = $request->getQuery(self::PARAM);

        if ($value === null || (!Config::storefront($siteId) && !Config::server($siteId))) {
            return null;
        }

        $added = 0;

        foreach (is_string($value) ? self::pairs($value) : [] as [$id, $quantity]) {
            $added += self::add($siteId, $id, $quantity) ? 1 : 0;
        }

        [$path, $query] = self::split((string) $request->getRequestUri());
        $target = $added > 0 ? self::cartPage($siteId) : $path;

        return $query === '' ? $target : $target . (str_contains($target, '?') ? '&' : '?') . $query;
    }

    /**
     * Pairs "id:quantity" of the value, the first 20: the quantity after the last colon, clamped to 1..99; a pair
     * without a positive integer id or an integer quantity is ignored.
     *
     * @return list<array{0: int, 1: int}>
     */
    public static function pairs(string $value): array
    {
        $pairs = [];

        foreach (array_slice(explode(',', $value), 0, self::MAX_PAIRS) as $pair) {
            $colon = strrpos($pair, ':');

            if ($colon === false) {
                continue;
            }

            $id = trim(substr($pair, 0, $colon));
            $quantity = trim(substr($pair, $colon + 1));

            if (preg_match('/^[1-9]\d{0,17}$/', $id) !== 1 || preg_match('/^[+-]?\d+$/', $quantity) !== 1) {
                continue;
            }

            $pairs[] = [(int) $id, max(1, min(self::MAX_QUANTITY, (int) $quantity))];
        }

        return $pairs;
    }

    /**
     * The cart page of the site: the setting "Cart page", the standard /personal/cart/ of the site folder otherwise.
     */
    public static function cartPage(string $siteId): string
    {
        $page = Config::get('cart_page', $siteId);

        if (preg_match('#^/(?![/\\\\])[^\s]*$#', $page) === 1) {
            return $page;
        }

        $site = SiteTable::getRow(['select' => ['DIR'], 'filter' => ['=LID' => $siteId]]);

        return rtrim((string) ($site['DIR'] ?? '/'), '/') . '/personal/cart/';
    }

    /**
     * One product of the link into the cart: the catalog checks activity, availability, the parent of an offer and
     * the permissions of the visitor. False — skipped.
     */
    private static function add(string $siteId, int $id, int $quantity): bool
    {
        Loader::includeModule('iblock');
        Loader::includeModule('catalog');
        Loader::includeModule('sale');
        $productId = self::element($siteId, $id);
        $product = $productId !== null ? ProductTable::getRow(['select' => ['TYPE', 'AVAILABLE'], 'filter' => ['=ID' => $productId]]) : null;

        if ($productId === null || $product === null || $product['AVAILABLE'] !== ProductTable::STATUS_YES) {
            return false;
        }

        // A product with offers: the link names no offer.
        $offers = \CCatalogSku::getExistOffers([$productId]);

        if (in_array((int) $product['TYPE'], [ProductTable::TYPE_SKU, ProductTable::TYPE_EMPTY_SKU], true) || !empty($offers[$productId])) {
            return false;
        }

        $result = \Bitrix\Catalog\Product\Basket::addProduct(['PRODUCT_ID' => $productId, 'QUANTITY' => $quantity], ['LID' => $siteId]);

        if (!$result->isSuccess()) {
            Queue::log('cart link of site ' . $siteId . ', product ' . $productId . ': ' . implode('; ', $result->getErrorMessages()));

            return false;
        }

        return true;
    }

    /**
     * The active element of a product or an offer of the site: the id itself in a catalog infoblock of the site (or
     * its offers), or the copy of a catalog element in another language (the same XML_ID).
     */
    private static function element(string $siteId, int $id): ?int
    {
        $products = Catalog::iblocks($siteId);

        if ($products === []) {
            return null;
        }

        $iblocks = $products;
        $rows = CatalogIblockTable::getList(['select' => ['IBLOCK_ID'], 'filter' => ['@PRODUCT_IBLOCK_ID' => $products]]);

        while ($row = $rows->fetch()) {
            $iblocks[] = (int) $row['IBLOCK_ID'];
        }

        $select = ['ID', 'IBLOCK_ID', 'XML_ID', 'ACTIVE'];
        $element = \CIBlockElement::GetList([], ['ID' => $id, 'CHECK_PERMISSIONS' => 'N'], false, false, $select)->Fetch();

        if (is_array($element) && !in_array((int) $element['IBLOCK_ID'], $iblocks, true)) {
            $element = (string) $element['XML_ID'] !== '' && Catalog::isCatalogElement($id)
                ? \CIBlockElement::GetList([], ['IBLOCK_ID' => $iblocks, '=XML_ID' => (string) $element['XML_ID'], 'CHECK_PERMISSIONS' => 'N'], false, ['nTopCount' => 1], $select)->Fetch()
                : false;
        }

        return is_array($element) && $element['ACTIVE'] === 'Y' ? (int) $element['ID'] : null;
    }

    /**
     * The path of the request and its query without the cart parameter (the parts as they came, in their order).
     *
     * @return array{0: string, 1: string}
     */
    private static function split(string $uri): array
    {
        [$path, $query] = array_pad(explode('?', $uri, 2), 2, '');
        $kept = [];

        foreach (explode('&', $query) as $part) {
            // parse_str names the parameter as PHP does for $_GET ("onetrace.cart" is onetrace_cart too).
            parse_str($part, $parsed);

            if ($part !== '' && !array_key_exists(self::PARAM, $parsed)) {
                $kept[] = $part;
            }
        }

        return [$path !== '' ? $path : '/', implode('&', $kept)];
    }
}
