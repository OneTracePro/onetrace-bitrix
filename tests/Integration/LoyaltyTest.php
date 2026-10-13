<?php

declare(strict_types=1);

namespace Onetrace\Connector\Tests\Integration;

use Bitrix\Sale\Order;
use OneTrace\Http\Request;
use OneTrace\Http\Response;
use Onetrace\Connector\Config;
use Onetrace\Connector\Handler\Loyalty as Handler;
use Onetrace\Connector\Loyalty;
use Onetrace\Connector\LoyaltyTable;
use Onetrace\Connector\Tests\TestCase;

/**
 * Bonus points at checkout: the hold, the points as a separate paid payment or as a discount of the lines, the hold
 * again within the allowed share when the basket changed, the confirmation with the order number, the amount of the
 * order paid with money, the block of a guest.
 */
final class LoyaltyTest extends TestCase
{
    private int $holds = 0;

    private int $user;

    protected function setUp(): void
    {
        parent::setUp();
        Loyalty::install();
        $this->user = $this->user();
        $GLOBALS['USER']->Authorize($this->user);
        unset($_SESSION['ONETRACE_LOYALTY']);

        $this->platform->responder = function (Request $request): ?Response {
            $path = (string) parse_url($request->getUrl(), PHP_URL_PATH);
            $body = json_decode((string) $request->getBody(), true);

            if ($path === '/api/v1/loyalty/calculate') {
                $max = (int) floor($body['amount'] * 0.3);

                return $this->json(200, ['member' => true, 'balance' => 500, 'max_points' => $max, 'max_discount' => $max, 'earn_points' => 4, 'point_value' => 1, 'currency' => 'RUB']);
            }

            if ($path === '/api/v1/loyalty/redemptions') {
                $this->holds++;

                return $this->json(201, ['id' => 'r' . $this->holds, 'status' => 'held', 'points' => $body['points'], 'discount' => (float) $body['points'], 'held_until' => gmdate('c', time() + 1800)]);
            }

            if (preg_match('#/confirm$#', $path) === 1) {
                return $this->json(200, ['status' => 'confirmed']);
            }

            return null;
        };
    }

    protected function tearDown(): void
    {
        $GLOBALS['USER']->Logout();
        unset($_SESSION['ONETRACE_LOYALTY']);
        Config::set('loyalty', 'N', self::SITE);
    }

    public function testThePointsAreAPaidPaymentAndTheOrderConfirmsThem(): void
    {
        Config::set('loyalty', Loyalty::MODE_PAYMENT, self::SITE);
        Loyalty::apply(self::SITE, 20, 99.9);

        $order = $this->order($this->product(), $this->user, 2, 'anna@example.com', [], static fn (Order $order) => Loyalty::applyToOrder($order));
        $payments = [];

        foreach ($order->getPaymentCollection() as $payment) {
            $payments[] = [(int) $payment->getPaymentSystemId() === Loyalty::paySystemId(), (float) $payment->getSum(), $payment->isPaid()];
        }

        sort($payments);
        self::assertEqualsWithDelta(99.9, (float) $order->getPrice(), 0.001, 'Prices stay as they are.');
        self::assertSame([[false, 79.9, false], [true, 20.0, true]], $payments);
        self::assertSame(['order_id' => (string) ($order->getField('ACCOUNT_NUMBER') ?: $order->getId())], $this->request('/api/v1/loyalty/redemptions/r1/confirm')['body']);
        self::assertNotNull(LoyaltyTable::getByPrimary($order->getId())->fetch()['CONFIRMED_AT']);
        self::assertNull(Loyalty::hold(self::SITE));

        $user = $params = [];
        $result = ['JS_DATA' => ['TOTAL' => ['ORDER_TOTAL_PRICE' => 99.9]]];
        Handler::onResultPrepared($order, $user, null, $params, $result);
        self::assertSame(79.9, round($result['JS_DATA']['TOTAL']['ORDER_TOTAL_LEFT_TO_PAY'], 2), 'The checkout total shows the points as paid.');
        self::assertStringContainsString('20', $result['JS_DATA']['TOTAL']['PAYED_FROM_ACCOUNT_FORMATED']);

        $this->flush();
        [$completed] = $this->platform->events('order_completed');
        self::assertSame(79.9, $completed['properties']['amount'], 'The platform gets the amount paid with money.');
    }

    public function testThePointsAreADiscountOfTheLinesInTheDiscountMode(): void
    {
        Config::set('loyalty', Loyalty::MODE_DISCOUNT, self::SITE);
        Loyalty::apply(self::SITE, 20, 99.9);

        $order = $this->order($this->product(), $this->user, 2, 'anna@example.com', [], static function (Order $order): void {
            Loyalty::applyToOrder($order);
            $order->getPaymentCollection()->current()->setField('SUM', $order->getPrice());
        });

        self::assertEqualsWithDelta(79.9, (float) $order->getPrice(), 0.001);
        self::assertSame('Y', $order->getBasket()->current()->getField('CUSTOM_PRICE'));
        self::assertNotNull($this->request('/api/v1/loyalty/redemptions/r1/confirm'));
    }

    public function testAChangedBasketIsHeldAgainWithinTheAllowedShare(): void
    {
        Config::set('loyalty', Loyalty::MODE_PAYMENT, self::SITE);
        Loyalty::apply(self::SITE, 29, 99.9);

        // One pair instead of two: 49.95 × 30 % → 14 points.
        $order = $this->order($this->product(), $this->user, 1, 'anna@example.com', [], static fn (Order $order) => Loyalty::applyToOrder($order));

        self::assertSame(2, $this->holds);
        self::assertSame(14, (int) LoyaltyTable::getByPrimary($order->getId())->fetch()['POINTS']);
        self::assertSame(14, $this->request('/api/v1/loyalty/redemptions')['body']['points']);
    }

    public function testAGuestGetsTheSignInHintAndNothingIsHeld(): void
    {
        Config::set('loyalty', Loyalty::MODE_PAYMENT, self::SITE);
        $GLOBALS['USER']->Logout();

        self::assertNull(Loyalty::offer(self::SITE, 100.0));
        self::assertSame([], array_filter($this->platform->requests, static fn (array $request): bool => str_contains($request['path'], '/loyalty/')));
        $this->expectException(\InvalidArgumentException::class);
        Loyalty::apply(self::SITE, 10, 100.0);
    }

    public function testTheServiceStateOfAMember(): void
    {
        Config::set('loyalty', Loyalty::MODE_PAYMENT, self::SITE);
        $basket = \Bitrix\Sale\Basket::loadItemsForFUser(\Bitrix\Sale\Fuser::getId(), self::SITE);
        $item = $basket->createItem('catalog', $this->product());
        $item->setFields(['QUANTITY' => 2, 'CURRENCY' => 'RUB', 'LID' => self::SITE, 'PRODUCT_PROVIDER_CLASS' => \Bitrix\Catalog\Product\CatalogProvider::class]);
        $basket->save();

        try {
            $state = Handler::state(self::SITE);
            self::assertSame(29, $state['max']);
            self::assertStringContainsString('500', (string) $state['balance']);

            Loyalty::apply(self::SITE, 10);
            self::assertStringContainsString('10', (string) Handler::state(self::SITE)['applied']);
        } finally {
            foreach ($basket as $line) {
                $line->delete();
            }

            $basket->save();
        }
    }

    /**
     * @return array{method: string, path: string, body: mixed}|null
     */
    private function request(string $path): ?array
    {
        foreach (array_reverse($this->platform->requests) as $request) {
            if ($request['path'] === $path) {
                return $request;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(int $status, array $body): Response
    {
        return new Response($status, ['content-type' => 'application/json'], (string) json_encode($body));
    }
}
