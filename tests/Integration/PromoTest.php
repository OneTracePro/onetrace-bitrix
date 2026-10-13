<?php

declare(strict_types=1);

namespace Onetrace\Connector\Tests\Integration;

use Bitrix\Sale\Internals\DiscountCouponTable;
use OneTrace\Http\Request;
use OneTrace\Http\Response;
use Onetrace\Connector\Config;
use Onetrace\Connector\Loyalty;
use Onetrace\Connector\PromoSync;
use Onetrace\Connector\Referrals;
use Onetrace\Connector\Tests\TestCase;

/**
 * Promo codes and referrals: codes of a pool become one-order coupons of the bound cart rule (by the cursor, with the
 * expiry of the code, coupons of other rules untouched); the friend's code of a new user waits for the agent and is
 * repeated while the platform does not know the profile.
 */
final class PromoTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private $codes = [];

    /** @var int */
    private $referStatus = 200;

    /** @var int */
    private $ruleId;

    /** @var int */
    private $otherRuleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ruleId = $this->rule('OneTrace welcome');
        $this->otherRuleId = $this->rule('Store own');
        Config::set('promo_pools', "welcome = {$this->ruleId}\nmissing = 999999", self::SITE);
        Config::set('promo_cursors', '', self::SITE);
        Config::set('referrals_pending', '');

        foreach (['WEL-AAAA', 'WEL-BBBB', 'STORE-OWN'] as $code) {
            if ($row = DiscountCouponTable::getRow(['select' => ['ID'], 'filter' => ['=COUPON' => $code]])) {
                DiscountCouponTable::delete((int) $row['ID']);
            }
        }

        DiscountCouponTable::add(['DISCOUNT_ID' => $this->otherRuleId, 'COUPON' => 'STORE-OWN', 'ACTIVE' => 'Y', 'TYPE' => DiscountCouponTable::TYPE_MULTI_ORDER]);

        $this->platform->responder = function (Request $request): ?Response {
            $path = (string) parse_url($request->getUrl(), PHP_URL_PATH);
            parse_str((string) parse_url($request->getUrl(), PHP_URL_QUERY), $query);

            if ($path === '/api/v1/promo/pools/welcome/codes') {
                return $this->json(200, ['data' => ($query['since'] ?? '') === 'c2' ? [] : $this->codes, 'next' => 'c2']);
            }

            if ($path === '/api/v1/promo/pools/missing/codes') {
                return $this->json(404, ['message' => 'Not found']);
            }

            if ($path === '/api/v1/loyalty/referrals') {
                return $this->json($this->referStatus, $this->referStatus === 422 ? ['message' => 'Own code', 'errors' => ['code' => ['Own code']]] : ($this->referStatus === 404 ? ['message' => 'Not found'] : ['status' => 'pending', 'rewarded' => true]));
            }

            return null;
        };
    }

    protected function tearDown(): void
    {
        Config::set('promo_pools', '', self::SITE);
        Config::set('loyalty', 'N', self::SITE);
        unset($_COOKIE['cdp_ref']);
    }

    public function testCodesOfAPoolBecomeCouponsOfTheBoundRule(): void
    {
        $this->codes = [
            ['code' => 'WEL-AAAA', 'status' => 'available', 'expires_at' => null],
            ['code' => 'WEL-BBBB', 'status' => 'issued', 'expires_at' => '2026-11-30T00:00:00Z'],
            ['code' => 'STORE-OWN', 'status' => 'available', 'expires_at' => null],
        ];

        self::assertSame([self::SITE => ['welcome' => 2]], PromoSync::run());

        $coupon = DiscountCouponTable::getRow(['select' => ['DISCOUNT_ID', 'TYPE', 'ACTIVE_TO'], 'filter' => ['=COUPON' => 'WEL-BBBB']]);
        self::assertSame([$this->ruleId, DiscountCouponTable::TYPE_ONE_ORDER], [(int) $coupon['DISCOUNT_ID'], (int) $coupon['TYPE']]);
        self::assertSame('2026-11-30', gmdate('Y-m-d', $coupon['ACTIVE_TO']->getTimestamp()));
        self::assertSame($this->otherRuleId, (int) DiscountCouponTable::getRow(['select' => ['DISCOUNT_ID'], 'filter' => ['=COUPON' => 'STORE-OWN']])['DISCOUNT_ID']);
        self::assertSame([self::SITE => ['welcome' => 0]], PromoSync::run());
    }

    public function testTheFriendsCodeOfANewUserIsSentByTheAgentAndRepeatedWhileTheProfileIsUnknown(): void
    {
        Config::set('loyalty', Loyalty::MODE_PAYMENT, self::SITE);
        $_COOKIE['cdp_ref'] = 'k7m2qx9a';
        Referrals::registered(77, self::SITE);
        self::assertSame(0, Referrals::process(), 'Not due yet: the identify of the user goes first.');

        $this->due();
        $this->referStatus = 404;
        self::assertSame(0, Referrals::process());
        self::assertSame(['profile' => ['type' => 'user_id', 'value' => '77'], 'code' => 'K7M2QX9A'], $this->lastRequest('/api/v1/loyalty/referrals')['body']);
        self::assertSame(2, Referrals::pending()[0]['attempt']);

        $this->due();
        $this->referStatus = 200;
        self::assertSame(1, Referrals::process());
        self::assertSame([], Referrals::pending());

        Config::set('loyalty', 'N', self::SITE);
        Referrals::registered(78, self::SITE);
        self::assertSame([], Referrals::pending(), 'Without bonus points the code is not sent.');
    }

    private function due(): void
    {
        Config::set('referrals_pending', (string) json_encode(array_map(static function (array $item): array {
            return ['at' => 0] + $item;
        }, Referrals::pending())));
    }

    private function rule(string $name): int
    {
        $result = \Bitrix\Sale\Internals\DiscountTable::add([
            'LID' => self::SITE, 'NAME' => $name, 'ACTIVE' => 'Y', 'CURRENCY' => 'RUB', 'USE_COUPONS' => 'Y',
            'ACTIONS_LIST' => ['CLASS_ID' => 'CondGroup', 'DATA' => ['All' => 'AND'], 'CHILDREN' => []],
            'CONDITIONS_LIST' => ['CLASS_ID' => 'CondGroup', 'DATA' => ['All' => 'AND', 'True' => 'True'], 'CHILDREN' => []],
        ]);

        return (int) $result->getId();
    }

    /**
     * @return array{method: string, path: string, body: mixed}
     */
    private function lastRequest(string $path): array
    {
        $found = array_values(array_filter($this->platform->requests, static function (array $request) use ($path): bool {
            return $request['path'] === $path;
        }));

        return $found[\count($found) - 1];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(int $status, array $body): Response
    {
        return new Response($status, ['content-type' => 'application/json'], (string) json_encode($body));
    }
}
