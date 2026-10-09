<?php

declare(strict_types=1);

namespace Onetrace\Connector\Search;

use OneTrace\Commerce\Customer;
use Onetrace\Connector\Config;
use Onetrace\Connector\Connection;
use Onetrace\Connector\Queue;

/**
 * Product search of a site from OneTrace (ADR-079 of the platform): products and their order for the results page,
 * the redirect of a query rule; any API error — null, the store's own search runs.
 */
final class Search
{
    public const MAX_PER_PAGE = 48;

    public static function enabled(string $siteId): bool
    {
        return Config::storefront($siteId) && Config::get('part_search', $siteId, 'N') === 'Y';
    }

    /**
     * @return array{ids: list<int>, total: int, redirect: string|null, request_id: string}|null
     */
    public static function products(string $siteId, string $query, int $page, int $perPage, ?string $sort = null): ?array
    {
        try {
            $result = Connection::client($siteId, 'search')->search()->products($query, array_filter([
                'page' => max(1, $page),
                'per_page' => max(1, min(self::MAX_PER_PAGE, $perPage)),
                'sort' => $sort,
                'anonymousId' => Connection::anonymousId(),
                'language' => Config::language($siteId),
            ]));
        } catch (\Throwable $error) {
            Queue::log('search fell back to Bitrix: ' . $error->getMessage());

            return null;
        }

        $ids = [];

        foreach ((array) ($result['items'] ?? []) as $item) {
            if (is_array($item) && ctype_digit((string) ($item['id'] ?? ''))) {
                $ids[] = (int) $item['id'];
            }
        }

        $redirect = (string) ($result['redirect'] ?? '');

        return [
            'ids' => $ids,
            'total' => (int) ($result['total'] ?? 0),
            'redirect' => preg_match('#^https?://[^\s/]+#i', $redirect) === 1 ? $redirect : null,
            'request_id' => (string) ($result['request_id'] ?? ''),
        ];
    }

    /**
     * The search event of the results page from the server: counted with the composite cache and blockers too.
     */
    public static function track(string $siteId, string $query, int $results, string $requestId): void
    {
        global $USER;

        if (!Config::server($siteId)) {
            return;
        }

        $userId = is_object($USER) && $USER->IsAuthorized() ? (string) $USER->GetID() : null;
        $anonymousId = Connection::anonymousId();

        if ($userId !== null || $anonymousId !== null) {
            Queue::event($siteId, Connection::messages($siteId)->search(new Customer($userId, $anonymousId), $query, $results, $requestId !== '' ? $requestId : null));
        }
    }

    /**
     * Sorting of the catalog component (ELEMENT_SORT_FIELD) in the terms of the platform: price — by price, the rest —
     * by relevance.
     */
    public static function sort(string $field, string $order): ?string
    {
        if (preg_match('/^(CATALOG_PRICE|SCALED_PRICE|PRICE)/i', $field) === 1) {
            return strtolower($order) === 'desc' ? 'price_desc' : 'price_asc';
        }

        return null;
    }
}
