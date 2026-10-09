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
    public const PARTS = ['tracker', 'orders', 'cart', 'catalog', 'newsletter'];

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

    public static function widget(string $place, ?string $siteId = null): string
    {
        $uid = self::get('widget_' . $place, $siteId);

        return preg_match('/^[a-z0-9]{1,16}$/', $uid) === 1 ? $uid : '';
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

    /** Language of a site: "ru". */
    public static function language(string $siteId): string
    {
        return self::sites()[$siteId] ?? 'ru';
    }
}
