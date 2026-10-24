<?php

declare(strict_types=1);

namespace Onetrace\Connector;

use Bitrix\Main\Config\Option;
use Bitrix\Main\SiteTable;

/**
 * Settings of the module by site (Settings → Module settings → OneTrace): each site can send to its own project.
 * A value set for a site wins over the common one.
 */
final class Config
{
    public const MODULE = 'onetrace.connector';

    /** Parts of the module that can be turned off one by one, all on by default except search. */
    public const PARTS = ['tracker', 'orders', 'cart', 'catalog', 'newsletter', 'notify', 'ask'];

    public static function get(string $name, ?string $siteId = null, string $default = ''): string
    {
        return trim((string) Option::get(self::MODULE, $name, $default, $siteId ?: false));
    }

    public static function set(string $name, string $value, ?string $siteId = null): void
    {
        Option::set(self::MODULE, $name, $value, $siteId ?: '');
    }

    public static function url(?string $siteId = null): string
    {
        return rtrim(self::get('url', $siteId), '/');
    }

    public static function writeKey(?string $siteId = null): string
    {
        return self::get('write_key', $siteId);
    }

    public static function secretKey(?string $siteId = null): string
    {
        return self::get('secret_key', $siteId);
    }

    public static function enabled(string $part, ?string $siteId = null): bool
    {
        return self::get('part_' . $part, $siteId, 'Y') === 'Y';
    }

    /** The server part works: the address and the secret key are set. */
    public static function server(?string $siteId = null): bool
    {
        return self::url($siteId) !== '' && self::secretKey($siteId) !== '';
    }

    /** The storefront part works: the address and the write key are set. */
    public static function storefront(?string $siteId = null): bool
    {
        return self::url($siteId) !== '' && self::writeKey($siteId) !== '';
    }

    /** Subscription topic of newsletter consents; null — the whole email channel. */
    public static function consentTopic(?string $siteId = null): ?string
    {
        $topic = self::get('consent_topic', $siteId);

        return preg_match('/^[a-z0-9_-]{1,64}$/', $topic) === 1 ? $topic : null;
    }

    /** Code of an order property (Y/N) with the newsletter consent of the checkout; empty — none. */
    public static function consentProperty(?string $siteId = null): string
    {
        $code = self::get('consent_property', $siteId);

        return preg_match('/^[A-Za-z0-9_]{1,50}$/', $code) === 1 ? $code : '';
    }

    /**
     * Order statuses that mean the customer received the order (order_delivered): "Выполнен" (F) by default.
     *
     * @return list<string>
     */
    public static function deliveredStatuses(?string $siteId = null): array
    {
        return array_values(array_filter(explode(',', self::get('delivered_statuses', $siteId, 'F')), static fn (string $status): bool => preg_match('/^[A-Za-z0-9]{1,2}$/', $status) === 1));
    }

    public static function widget(string $place, ?string $siteId = null): string
    {
        $uid = self::get('widget_' . $place, $siteId);

        return preg_match('/^[a-z0-9]{1,16}$/', $uid) === 1 ? $uid : '';
    }

    /**
     * The "Notify when available" form of the platform (the component onetrace:catalog.notify): the tracker draws it,
     * so it needs the storefront part.
     */
    public static function notify(?string $siteId = null): bool
    {
        return self::storefront($siteId) && self::enabled('tracker', $siteId) && self::enabled('notify', $siteId);
    }

    /**
     * "Ask AI about this product" of the platform on the product page (the component onetrace:product.ask and the
     * automatic place in the standard catalog.element templates): the tracker draws it, so it needs the storefront part.
     */
    public static function ask(?string $siteId = null): bool
    {
        return self::storefront($siteId) && self::enabled('tracker', $siteId) && self::enabled('ask', $siteId);
    }

    public static function push(?string $siteId = null): bool
    {
        return self::get('push', $siteId, 'N') === 'Y';
    }

    /**
     * Active sites with their language: id => language id ("ru").
     *
     * @return array<string, string>
     */
    public static function sites(): array
    {
        $sites = [];
        $rows = SiteTable::getList(['select' => ['LID', 'LANGUAGE_ID'], 'filter' => ['=ACTIVE' => 'Y'], 'order' => ['SORT' => 'ASC', 'LID' => 'ASC']]);

        while ($row = $rows->fetch()) {
            $sites[(string) $row['LID']] = (string) $row['LANGUAGE_ID'];
        }

        return $sites;
    }

    /**
     * Sites whose catalog is uploaded: one per project (the first site with the same address and key), so sites of
     * one project do not overwrite each other's products.
     *
     * @return list<string>
     */
    public static function catalogSites(): array
    {
        $sites = [];

        foreach (array_keys(self::sites()) as $siteId) {
            if (self::server($siteId) && self::enabled('catalog', $siteId)) {
                $sites[self::url($siteId) . '|' . self::secretKey($siteId)] ??= $siteId;
            }
        }

        return array_values($sites);
    }

    /**
     * Other languages of the catalog of a site from catalogSites(): sites of the same project with another language
     * (the first one per language). Their copies of the products (the same XML_ID) are translations.
     *
     * @return array<string, string> language ("en") => site id
     */
    public static function translationSites(string $mainSiteId): array
    {
        $project = self::url($mainSiteId) . '|' . self::secretKey($mainSiteId);
        $main = self::language($mainSiteId);
        $languages = [];

        foreach (self::sites() as $siteId => $language) {
            if ($language !== $main && self::server($siteId) && self::enabled('catalog', $siteId) && self::url($siteId) . '|' . self::secretKey($siteId) === $project) {
                $languages[$language] ??= $siteId;
            }
        }

        return $languages;
    }

    /**
     * The site gives translations to the catalog of another site of its project (translationSites()): its products are
     * copies with their own ids, the platform knows them by the ids of the main site.
     */
    public static function translationSite(string $siteId): bool
    {
        foreach (self::catalogSites() as $mainSiteId) {
            if ($mainSiteId !== $siteId && in_array($siteId, self::translationSites($mainSiteId), true)) {
                return true;
            }
        }

        return false;
    }

    /** Language of a site: "ru". */
    public static function language(string $siteId): string
    {
        return self::sites()[$siteId] ?? 'ru';
    }
}
