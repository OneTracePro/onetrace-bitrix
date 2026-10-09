<?php

declare(strict_types=1);

namespace Onetrace\Connector;

use Bitrix\Main\Application;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\SiteTable;
use OneTrace\Client;
use OneTrace\Commerce\Messages;

/**
 * Library clients by site and the message builder of a site. Server messages go with the secret key only: they
 * carry consents, which the platform accepts only with it.
 */
final class Connection
{
    public const VERSION = '1.1.0';

    /** @var array<string, Client> */
    private static array $clients = [];

    /** @var array<string, mixed> extra options of the client: tests pass a transport here */
    private static array $options = [];

    /**
     * @param string $key "secret", "write" or "search" (the write key with short timeouts: a visitor waits for it)
     */
    public static function client(string $siteId, string $key = 'secret'): Client
    {
        $cacheKey = $siteId . ':' . $key;
        $write = $key === 'write' || $key === 'search';

        if (!isset(self::$clients[$cacheKey])) {
            self::$clients[$cacheKey] = new Client(Config::url($siteId), [
                $write ? 'write_key' : 'secret_key' => ($write ? Config::writeKey($siteId) : Config::secretKey($siteId)) ?: null,
                'timeout' => $key === 'search' ? 3.0 : 10.0,
                'connect_timeout' => $key === 'search' ? 2.0 : 5.0,
                // The queue retries with long pauses; the client must not hold the agent.
                'max_retries' => 0,
                'user_agent' => sprintf('onetrace-bitrix/%s Bitrix/%s', self::VERSION, (string) ModuleManager::getVersion('main')),
            ] + self::$options);
        }

        return self::$clients[$cacheKey];
    }

    public static function messages(string $siteId): Messages
    {
        return new Messages('bitrix', self::siteUrl($siteId));
    }

    /** A new client after the settings change, and a test transport. */
    public static function reset(array $options = []): void
    {
        self::$clients = [];
        self::$options = $options;
    }

    /** Value of the tracker cookie: links a server request to the visitor's browser events. */
    public static function anonymousId(): ?string
    {
        $value = trim((string) Application::getInstance()->getContext()->getRequest()->getCookieRaw('cdp_aid'));

        return $value !== '' && strlen($value) <= 255 && preg_match('/^[\w.:-]+$/', $value) === 1 ? $value : null;
    }

    /** Address of a site: its server name, otherwise the server name of the module settings. */
    public static function siteUrl(string $siteId): string
    {
        $site = SiteTable::getRow(['select' => ['SERVER_NAME', 'DIR'], 'filter' => ['=LID' => $siteId]]);
        $host = (string) ($site['SERVER_NAME'] ?? '') ?: (string) \COption::GetOptionString('main', 'server_name', 'localhost');
        $scheme = preg_match('#^https?://#i', $host) === 1 ? '' : (\CMain::IsHTTPS() ? 'https://' : 'http://');

        return rtrim($scheme . $host, '/') . '/' . ltrim((string) ($site['DIR'] ?? '/'), '/');
    }
}
