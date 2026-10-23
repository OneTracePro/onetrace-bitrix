<?php

declare(strict_types=1);

namespace Onetrace\Connector\Search;

use Bitrix\Main\Application;
use Onetrace\Connector\Config;
use Onetrace\Connector\Connection;
use Onetrace\Connector\Queue;

/**
 * The search widget of OneTrace (Site → Search of the platform, ADR-127): with the module setting "OneTrace search
 * widget" on and the widget enabled in the project, the tracker draws the search layer under the search fields and
 * the results (facets, sorting, scrolling, "Add to cart") in the container of onetrace:catalog.search; the module adds
 * the products to the cart by its service cart.php. The settings of the platform are cached for TTL; an API error or
 * the widget off — the search of the module as before.
 */
final class Widget
{
    /** Cached settings live this long (seconds); a failed request — FAILURE_TTL, then the module asks again. */
    public const TTL = 600;

    public const FAILURE_TTL = 60;

    private const CACHE_DIR = 'onetrace_search';

    /** The widget draws the search of the site: the setting, the tracker and the widget enabled in the project. */
    public static function active(string $siteId): bool
    {
        return Config::storefront($siteId) && Config::enabled('tracker', $siteId) && Config::get('part_search_widget', $siteId, 'N') === 'Y'
            && self::enabled($siteId);
    }

    /**
     * The container of the results drawn by the tracker: the query in "q", the cart service of the module ({id} — the
     * catalog id of the product).
     */
    public static function container(string $siteId): string
    {
        return sprintf('<div data-cdp-search-results data-query-param="q" data-cart-url="%s"></div>', htmlspecialcharsbx(self::cartUrl($siteId)));
    }

    public static function cartUrl(string $siteId): string
    {
        return '/bitrix/services/' . Config::MODULE . '/cart.php?site=' . rawurlencode($siteId) . '&id={id}';
    }

    /**
     * The mini-cart of the theme after "Add to cart" of the widget: sale.basket.basket.line and the templates of
     * Bitrix reload on OnBasketChange.
     */
    public static function script(): string
    {
        return "<script>window.addEventListener('cdp:add-to-cart',function(){if(window.BX&&BX.onCustomEvent){BX.onCustomEvent('OnBasketChange')}});</script>";
    }

    /** Forgets the cached settings of all sites (the module settings were saved). */
    public static function forget(): void
    {
        Application::getInstance()->getManagedCache()->cleanDir(self::CACHE_DIR);
    }

    /**
     * enabled of GET /api/v1/search/widget for the write key and the language of the site, cached.
     */
    private static function enabled(string $siteId): bool
    {
        $cache = Application::getInstance()->getManagedCache();
        $language = Config::language($siteId);
        $id = 'onetrace_search_widget_' . md5(Config::url($siteId) . '|' . Config::writeKey($siteId) . '|' . $language);
        $value = $cache->read(self::TTL, $id, self::CACHE_DIR) ? $cache->get($id) : false;

        // Long agents and cron keep the cache of their first read: the time of the value decides.
        if (is_array($value) && ($value['at'] ?? 0) >= time() - (($value['ok'] ?? false) ? self::TTL : self::FAILURE_TTL)) {
            return (bool) ($value['enabled'] ?? false);
        }

        try {
            $enabled = (Connection::client($siteId, 'search')->search()->widget($language)['enabled'] ?? false) === true;
            $ok = true;
        } catch (\Throwable $error) {
            $enabled = false;
            $ok = false;
            Queue::log('search widget settings of site ' . $siteId . ': ' . $error->getMessage());
        }

        $cache->clean($id, self::CACHE_DIR);
        $cache->read(self::TTL, $id, self::CACHE_DIR);
        $cache->setImmediate($id, ['at' => time(), 'enabled' => $enabled, 'ok' => $ok]);

        return $enabled;
    }
}
