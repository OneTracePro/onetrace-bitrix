<?php

declare(strict_types=1);

namespace Onetrace\Connector\Search;

use Bitrix\Catalog\ProductTable;
use Bitrix\Main\Application;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Loader;
use Bitrix\Main\SiteTable;
use Bitrix\Sale\Basket;
use Bitrix\Sale\Fuser;
use Onetrace\Connector\Catalog;
use Onetrace\Connector\Connection;
use Onetrace\Connector\Queue;

/**
 * "Add to cart" of the search widget (/bitrix/services/onetrace.connector/cart.php?site=…&id=…): POST with quantity
 * and the X-Requested-With header — a request of another site cannot set it without a CORS preflight, so no sessid
 * is needed. id — the catalog id of OneTrace, the element of the product (offers are uploaded as their parent).
 *
 * A simple product goes to the cart of the visitor with the checks of the catalog (activity, permissions, stock):
 * {"ok": true, "count": lines in the cart}. A product with offers (a choice is needed), one that cannot be bought or
 * any error of the cart — {"ok": false, "redirect": its page}; an unknown id — the home page of the site.
 */
final class Cart
{
    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public static function endpoint(string $siteId, ?HttpRequest $request = null): array
    {
        $request ??= Application::getInstance()->getContext()->getRequest();

        if (!$request->isPost()) {
            return ['status' => 405, 'body' => ['ok' => false]];
        }

        if ($request->getHeader('X-Requested-With') !== 'XMLHttpRequest') {
            return ['status' => 400, 'body' => ['ok' => false]];
        }

        return self::add($siteId, (int) $request->getQuery('id'), max(1, (int) $request->getPost('quantity')), self::base($request, $siteId));
    }

    /**
     * @param string $base the address of the site for the links ("https://shop.example")
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public static function add(string $siteId, int $id, int $quantity, string $base): array
    {
        Loader::includeModule('iblock');
        Loader::includeModule('catalog');
        Loader::includeModule('sale');
        $element = $id > 0 ? self::element($siteId, $id) : null;

        if ($element === null) {
            return ['status' => 404, 'body' => ['ok' => false, 'redirect' => self::home($siteId, $base)]];
        }

        $productId = (int) $element['ID'];
        $page = (string) $element['~DETAIL_PAGE_URL'] !== '' ? self::absolute((string) $element['~DETAIL_PAGE_URL'], $base) : self::home($siteId, $base);
        $product = ProductTable::getRow(['select' => ['TYPE', 'AVAILABLE'], 'filter' => ['=ID' => $productId]]);
        $offers = \CCatalogSku::getExistOffers([$productId]);

        // Offers need a choice on the product page; a product that cannot be bought shows why there.
        if ($product === null || in_array((int) $product['TYPE'], [ProductTable::TYPE_SKU, ProductTable::TYPE_EMPTY_SKU], true) || !empty($offers[$productId])
            || $element['ACTIVE'] !== 'Y' || $product['AVAILABLE'] !== ProductTable::STATUS_YES) {
            return ['status' => 200, 'body' => ['ok' => false, 'redirect' => $page]];
        }

        $result = \Bitrix\Catalog\Product\Basket::addProduct(['PRODUCT_ID' => $productId, 'QUANTITY' => $quantity], ['LID' => $siteId]);

        if (!$result->isSuccess()) {
            Queue::log('search widget cart of site ' . $siteId . ', product ' . $productId . ': ' . implode('; ', $result->getErrorMessages()));

            return ['status' => 200, 'body' => ['ok' => false, 'redirect' => $page]];
        }

        return ['status' => 200, 'body' => ['ok' => true, 'count' => count(Basket::loadItemsForFUser(Fuser::getId(), $siteId)->getOrderableItems())]];
    }

    /**
     * The product element of the site: the id itself in a catalog infoblock of the site, or its copy in another
     * language (the same XML_ID) — the catalog of a project is uploaded from its first site.
     *
     * @return array<string, mixed>|null
     */
    private static function element(string $siteId, int $id): ?array
    {
        $iblocks = Catalog::iblocks($siteId);
        $select = ['ID', 'IBLOCK_ID', 'XML_ID', 'ACTIVE', 'DETAIL_PAGE_URL'];
        $element = \CIBlockElement::GetList([], ['ID' => $id, 'CHECK_PERMISSIONS' => 'N'], false, false, $select)->GetNext();

        if (!is_array($element) || $iblocks === []) {
            return null;
        }

        if (in_array((int) $element['IBLOCK_ID'], $iblocks, true)) {
            return $element;
        }

        $copy = (string) $element['~XML_ID'] !== ''
            ? \CIBlockElement::GetList([], ['IBLOCK_ID' => $iblocks, '=XML_ID' => (string) $element['~XML_ID'], 'CHECK_PERMISSIONS' => 'N'], false, ['nTopCount' => 1], $select)->GetNext()
            : false;

        return is_array($copy) ? $copy : null;
    }

    /** The address of the store the visitor is on, for the links of the answer. */
    private static function base(HttpRequest $request, string $siteId): string
    {
        // With the port: getHttpHost() of the request drops it.
        $host = strtolower((string) $request->getServer()->getHttpHost());

        return preg_match('/^[a-z0-9.-]+(:\d{1,5})?$/', $host) === 1 ? ($request->isHttps() ? 'https://' : 'http://') . $host : rtrim(Connection::siteUrl($siteId), '/');
    }

    /** The home page of the site ("/en/" of a site in a folder). */
    private static function home(string $siteId, string $base): string
    {
        $site = SiteTable::getRow(['select' => ['DIR'], 'filter' => ['=LID' => $siteId]]);

        return self::absolute((string) ($site['DIR'] ?? '/'), $base);
    }

    private static function absolute(string $path, string $base): string
    {
        return preg_match('#^https?://#i', $path) === 1 ? $path : $base . '/' . ltrim($path, '/');
    }
}
