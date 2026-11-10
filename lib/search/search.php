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
 *
 * AI search (the mode of Site → Search on the platform): the query goes to AI first (ai()) — it reads it as catalog
 * filters and the page shows the products found by them; the parse is waited for up to AI_WAIT seconds, otherwise (or
 * when the mode does not want the query) the usual results. Above the products of the first page — <div
 * data-cdp-search-ai>: the tracker writes the short AI answer there and draws the chips of what AI understood and the
 * refinements; they open the results again with ai_state (a removed chip), ai_refine + ai_previous (a refinement) or
 * ai=0 (the usual search).
 */
final class Search
{
    public const MAX_PER_PAGE = 48;

    /** Seconds the results page waits for the AI parse of a query that is not in the cache yet. */
    public const AI_WAIT = 2.5;

    /** Parameters of the links of the AI block (the tracker sets them). */
    public const AI_PARAMS = ['ai', 'ai_state', 'ai_refine', 'ai_previous'];

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
     * AI results of the query: the AI search mode is on and wants it, the parse came in AI_WAIT seconds; null — the
     * usual search (products()). block — the AI block for the first page ('' on the others), url — the first page of
     * the results of the query.
     *
     * @param array<string, mixed> $request the request parameters (ai, ai_state, ai_refine, ai_previous)
     *
     * @return array{ids: list<int>, total: int, redirect: null, request_id: string, block: string}|null
     */
    public static function ai(string $siteId, string $query, int $page, int $perPage, ?string $sort, string $url, array $request): ?array
    {
        $choice = (string) ($request['ai'] ?? '');
        $refine = trim((string) ($request['ai_refine'] ?? ''));
        $state = self::decoded($request['ai_state'] ?? null);
        $previous = $refine !== '' ? self::decoded($request['ai_previous'] ?? null) : null;

        if ($choice === '0' || Widget::aiMode($siteId) === null) {
            return null;
        }

        $question = $previous !== null ? mb_substr($refine, 0, 200) : $query;

        try {
            $result = Connection::client($siteId, 'search')->search()->ai($question, array_filter([
                'page' => max(1, $page),
                'per_page' => max(1, min(self::MAX_PER_PAGE, $perPage)),
                'sort' => $sort,
                'anonymousId' => Connection::anonymousId(),
                'language' => Config::language($siteId),
                'asked' => $choice === '1',
                'state' => $state,
                'previous' => $previous,
            ]), self::AI_WAIT);
        } catch (\Throwable $error) {
            Queue::log('AI search fell back to the usual search: ' . $error->getMessage());

            return null;
        }

        $ai = $result['ai'] ?? null;

        if (!is_array($ai)) {
            return null;
        }

        $ids = [];
        $products = [];

        foreach ((array) ($result['items'] ?? []) as $item) {
            if (is_array($item) && ctype_digit((string) ($item['id'] ?? ''))) {
                $ids[] = (int) $item['id'];
                $products[] = (string) $item['id'];
            }
        }

        $block = $page > 1 ? '' : '<div class="onetrace-search-ai" data-cdp-search-ai="' . htmlspecialcharsbx((string) json_encode([
            'url' => $url,
            'turns' => $previous !== null ? [$query, $question] : [$query],
            'state' => $ai['state'] ?? null,
            'understood' => $ai['understood'] ?? [],
            'refinements' => $ai['refinements'] ?? [],
            'relaxed' => (int) ($ai['relaxed'] ?? 0),
            'products' => array_slice($products, 0, 6),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"></div>';

        return ['ids' => $ids, 'total' => (int) ($result['total'] ?? 0), 'redirect' => null, 'request_id' => (string) ($result['request_id'] ?? ''), 'block' => $block];
    }

    /**
     * A parse in a link of the AI block: base64url of JSON; null — none or broken.
     *
     * @return array<string, mixed>|null
     */
    public static function decoded(mixed $raw): ?array
    {
        if (!is_string($raw) || $raw === '' || strlen($raw) > 8000 || preg_match('/^[A-Za-z0-9_-]+$/', $raw) !== 1) {
            return null;
        }

        $json = base64_decode(strtr($raw, '-_', '+/'), true);
        $value = $json !== false ? json_decode($json, true) : null;

        return is_array($value) ? $value : null;
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
