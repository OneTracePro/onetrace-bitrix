<?php

declare(strict_types=1);

namespace Onetrace\Connector;

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sale\Internals\DiscountCouponTable;
use Bitrix\Sale\Internals\DiscountTable;
use OneTrace\Exception\AuthenticationException;
use OneTrace\Exception\NotFoundException;

/**
 * Promo codes of OneTrace pools as coupons of cart rules (setting "promo_pools" of a site: "pool_key = rule id" per
 * line). Every 5 minutes (agent) new and changed codes of the pool come by the cursor of the platform and become
 * one-order coupons of the rule with the expiry of the code; coupons of other rules with the same code are left.
 */
final class PromoSync
{
    private const CURSORS = 'promo_cursors';

    private const BATCH = 1000;

    private const PAGE = 250;

    /**
     * Pools of the site bound to cart rules.
     *
     * @return array<string, int> pool key → rule id
     */
    public static function pools(string $siteId): array
    {
        $pools = [];

        foreach (preg_split('/\R/', Config::get('promo_pools', $siteId)) ?: [] as $line) {
            if (preg_match('/^\s*([a-z0-9_]{1,64})\s*=\s*(\d+)\s*$/', $line, $match) === 1) {
                $pools[$match[1]] = (int) $match[2];
            }
        }

        return $pools;
    }

    /**
     * @return array<string, array<string, int>> site → pool key → coupons created or updated
     */
    public static function run(): array
    {
        if (!Loader::includeModule('sale')) {
            return [];
        }

        $done = [];

        foreach (array_keys(Config::sites()) as $siteId) {
            if (!Config::server($siteId) || self::pools($siteId) === []) {
                continue;
            }

            $cursors = json_decode(Config::get(self::CURSORS, $siteId), true);
            $cursors = \is_array($cursors) ? $cursors : [];

            foreach (self::pools($siteId) as $pool => $ruleId) {
                if (!DiscountTable::getRow(['select' => ['ID'], 'filter' => ['=ID' => $ruleId]])) {
                    Queue::log(sprintf('promo %s: no cart rule %d', $pool, $ruleId));

                    continue;
                }

                try {
                    [$done[$siteId][$pool], $cursors[$pool]] = self::sync($siteId, $pool, $ruleId, isset($cursors[$pool]) ? (string) $cursors[$pool] : null);
                } catch (AuthenticationException $error) {
                    Queue::log('promo: ' . $error->getMessage());

                    break;
                } catch (NotFoundException $error) {
                    Queue::log(sprintf('promo %s: no such pool', $pool));
                } catch (\Throwable $error) {
                    Queue::log(sprintf('promo %s: %s', $pool, $error->getMessage()));
                }

                Config::set(self::CURSORS, (string) json_encode($cursors), $siteId);
            }
        }

        return $done;
    }

    /**
     * @return array{0: int, 1: string|null}
     */
    private static function sync(string $siteId, string $pool, int $ruleId, ?string $cursor): array
    {
        $changed = 0;
        $client = Connection::client($siteId);

        do {
            $page = $client->promo()->codes($pool, $cursor, self::PAGE);
            $codes = isset($page['data']) && \is_array($page['data']) ? $page['data'] : [];

            foreach ($codes as $code) {
                if (\is_array($code) && \is_string($code['code'] ?? null) && ($code['status'] ?? '') !== 'shared') {
                    $changed += self::coupon($ruleId, $code) ? 1 : 0;
                }
            }

            $cursor = isset($page['next']) && \is_string($page['next']) ? $page['next'] : $cursor;
        } while (\count($codes) >= self::PAGE && $changed < self::BATCH);

        return [$changed, $cursor];
    }

    /**
     * @param array<mixed> $code
     */
    private static function coupon(int $ruleId, array $code): bool
    {
        $value = (string) $code['code'];
        $time = \is_string($code['expires_at'] ?? null) ? strtotime($code['expires_at']) : false;
        $expires = $time !== false ? DateTime::createFromTimestamp($time) : null;
        $existing = DiscountCouponTable::getRow(['select' => ['ID', 'DISCOUNT_ID'], 'filter' => ['=COUPON' => $value]]);

        if ($existing) {
            if ((int) $existing['DISCOUNT_ID'] !== $ruleId) {
                return false;
            }

            return DiscountCouponTable::update((int) $existing['ID'], ['ACTIVE_TO' => $expires])->isSuccess();
        }

        return DiscountCouponTable::add([
            'DISCOUNT_ID' => $ruleId,
            'COUPON' => $value,
            'ACTIVE' => 'Y',
            'TYPE' => DiscountCouponTable::TYPE_ONE_ORDER,
            'ACTIVE_TO' => $expires,
            'DESCRIPTION' => 'OneTrace',
        ])->isSuccess();
    }
}
