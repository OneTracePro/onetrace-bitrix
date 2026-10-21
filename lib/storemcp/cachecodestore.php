<?php

declare(strict_types=1);

namespace Onetrace\Connector\StoreMcp;

use Bitrix\Main\Data\Cache;
use OneTrace\StoreMcp\CodeStore;

/**
 * Confirmation codes, their limits and answers to repeated requests in the Bitrix cache: a cleared cache only means
 * the customer asks for a new code. The expiry is kept with the value: the cache is read with the longest TTL.
 */
final class CacheCodeStore implements CodeStore
{
    private const DIR = '/onetrace_connector/store_mcp';

    private const MAX_TTL = 86400;

    public function get(string $key): ?array
    {
        $cache = Cache::createInstance();

        if (!$cache->initCache(self::MAX_TTL, self::id($key), self::DIR)) {
            return null;
        }

        $vars = $cache->getVars();

        return is_array($vars) && is_array($vars['value'] ?? null) && (int) ($vars['expires'] ?? 0) > time() ? $vars['value'] : null;
    }

    public function put(string $key, array $value, int $ttl): void
    {
        $cache = Cache::createInstance();
        $cache->clean(self::id($key), self::DIR);
        $cache->forceRewriting(true);
        $cache->startDataCache(min($ttl, self::MAX_TTL), self::id($key), self::DIR);
        $cache->endDataCache(['value' => $value, 'expires' => time() + $ttl]);
    }

    public function delete(string $key): void
    {
        Cache::createInstance()->clean(self::id($key), self::DIR);
    }

    private static function id(string $key): string
    {
        return 'code_' . sha1($key);
    }
}
