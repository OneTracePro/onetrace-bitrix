<?php

declare(strict_types=1);

namespace Onetrace\Connector\Handler;

use Bitrix\Main\Application;
use Bitrix\Main\Page\Asset;
use Bitrix\Main\Page\AssetLocation;
use OneTrace\Commerce\LineItem;
use Onetrace\Connector\Catalog;
use Onetrace\Connector\Config;
use Onetrace\Connector\Connection;
use Onetrace\Connector\Queue;

/**
 * Storefront pages (OnProlog): the tracker in <head> — the same for every visitor, so it is cached with the page
 * (composite site); product views from the view counter of the standard catalog.element templates.
 */
final class Page
{
    /** Methods the stub queues until cdp.js loads: the same list as in the platform's install code. */
    private const STUB_METHODS = ['init', 'page', 'track', 'identify', 'alias', 'reset', 'setLanguage', 'widgets', 'renderRecommendations', 'searchBoxes'];

    /** The view counter request of catalog.element (its templates post it after the product page loads). */
    private const VIEW_COUNTER = '/bitrix/components/bitrix/catalog.element/ajax.php';

    public static function onProlog(): void
    {
        if (defined('ADMIN_SECTION') || !defined('SITE_ID')) {
            return;
        }

        try {
            $request = Application::getInstance()->getContext()->getRequest();

            if ($request->getRequestedPage() === self::VIEW_COUNTER) {
                self::viewed();
            } elseif (!$request->isAjaxRequest()) {
                self::tracker((string) SITE_ID);
            }
        } catch (\Throwable $error) {
            Queue::log('page: ' . $error->getMessage());
        }
    }

    /**
     * The inline tracker: the stub queues calls until cdp.js loads; language — of the page.
     */
    public static function tracker(string $siteId): void
    {
        if (!Config::storefront($siteId) || !Config::enabled('tracker', $siteId)) {
            return;
        }

        $settings = [
            'script' => Config::url($siteId) . '/tracker/cdp.js',
            'key' => Config::writeKey($siteId),
            'options' => ['host' => Config::url($siteId), 'language' => 'page'],
            'methods' => self::STUB_METHODS,
        ];
        $script = sprintf(
            "<script>(function(w,d,s,c){c=w.cdp=w.cdp||[];if(c.version)return;s.methods.forEach(function(m){c[m]=c[m]||function(){c.push([m].concat([].slice.call(arguments)))}});"
            . "var e=d.createElement('script');e.async=1;e.src=s.script;d.head.appendChild(e);cdp.init(s.key,s.options);cdp.page()})(window,document,%s);</script>",
            json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS)
        );

        Asset::getInstance()->addString($script, false, AssetLocation::AFTER_JS_KERNEL);
    }

    /**
     * product_viewed from the server: the view counter carries the product (PARENT_ID) and the chosen offer (PRODUCT_ID).
     */
    private static function viewed(): void
    {
        $request = Application::getInstance()->getContext()->getRequest();
        $siteId = (string) $request->getPost('SITE_ID');
        $elementId = (int) $request->getPost('PRODUCT_ID');
        $parentId = (int) $request->getPost('PARENT_ID');

        if ($request->getPost('AJAX') !== 'Y' || $elementId <= 0 || preg_match('/^[a-z0-9_]{2}$/i', $siteId) !== 1) {
            return;
        }

        $customer = Sale::visitor($siteId);
        $productId = $parentId > 0 ? $parentId : $elementId;
        $brief = $customer !== null ? Catalog::brief($siteId, $productId) : null;

        if ($customer === null || $brief === null) {
            return;
        }

        $line = new LineItem($productId, $brief['name'], $brief['price'], 1, $elementId !== $productId ? $elementId : null, $brief['category']);
        Queue::event($siteId, Connection::messages($siteId)->product('product_viewed', $customer, $line, $brief['currency']));
    }
}
