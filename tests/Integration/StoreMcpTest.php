<?php

declare(strict_types=1);

namespace Onetrace\Connector\Tests\Integration;

use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\EventResult;
use Bitrix\Sale\Order;
use OneTrace\Http\Request;
use OneTrace\Http\Response;
use Onetrace\Connector\Config;
use Onetrace\Connector\StoreMcp;
use Onetrace\Connector\Tests\TestCase;

/**
 * MCP server of the store for the OneTrace AI chat bot: the platform key, the allowed tools, confirmation by a code
 * of the mail event, orders of the confirmed customer only, the cancellation of orders with nothing paid, requests
 * to the shop once per idempotency key, the chat token of a signed-in user and the connection to the bot.
 */
final class StoreMcpTest extends TestCase
{
    private const SHOP = 'shop@example.com';

    /** @var list<array<string, mixed>> emails Bitrix would send (main:OnBeforeMailSend), stopped there */
    private static array $mails = [];

    private static bool $listening = false;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('store_mcp', 'Y', self::SITE);
        Config::set('store_mcp_tools', 'store_start_verification,store_verify,store_get_customer,store_find_orders,store_get_order,store_get_tracking,store_check_availability,store_cancel_order,store_change_delivery', self::SITE);
        Option::set('sale', 'order_email', self::SHOP);
        StoreMcp::installMailEvents();
        self::$mails = [];

        if (!self::$listening) {
            self::$listening = true;
            EventManager::getInstance()->addEventHandler('main', 'OnBeforeMailSend', static function (\Bitrix\Main\Event $event): EventResult {
                self::$mails[] = $event->getParameter(0);

                return new EventResult(EventResult::ERROR);
            }, false, 10000);
        }

        // Store emails of the platform are off: Bitrix sends the code itself.
        $this->platform->responder = static fn (Request $request): ?Response => str_ends_with((string) parse_url($request->getUrl(), PHP_URL_PATH), '/emails/settings')
            ? new Response(403, ['content-type' => 'application/json'], '{"message":"No"}')
            : null;
    }

    protected function tearDown(): void
    {
        Config::set('store_mcp', 'N', self::SITE);
        Option::delete('sale', ['name' => 'order_email']);
        parent::tearDown();
    }

    public function testAnswersTheContractWithTheAllowedToolsForThePlatformKeyOnly(): void
    {
        $init = $this->rpc('initialize')['data']['result'];
        $tools = array_column($this->rpc('tools/list')['data']['result']['tools'], 'name');

        self::assertSame(['version' => 1], $init['capabilities']['experimental']['onetrace/store']);
        self::assertContains('store_cancel_order', $tools);
        self::assertNotContains('store_create_return', $tools);
        self::assertSame(401, $this->rpc('tools/list', [], 'wrong-key')['status']);
    }

    public function testConfirmsACustomerByACodeByEmailAndShowsOnlyTheirOrders(): void
    {
        // A new address every run: the code limit (5 an hour) lives in the Bitrix cache of the stand.
        $email = 'mcp-' . bin2hex(random_bytes(4)) . '@example.com';
        $product = $this->product('Linen Dress', 40.0);
        $mine = $this->order($product, $this->user($email), 1, $email);
        $other = $this->order($product, $this->user(), 1);

        self::assertSame('auth_required', $this->call('store_get_order', ['order' => self::number($mine)])['error']);

        self::assertTrue($this->call('store_start_verification', ['contact' => $email])['sent']);
        $codes = array_values(array_filter(self::$mails, static fn (array $mail): bool => $mail['TO'] === $email));
        self::assertCount(1, $codes);
        self::assertSame(1, preg_match('/\b(\d{6})\b/', (string) $codes[0]['BODY'], $code));

        self::assertSame('invalid_code', $this->call('store_verify', ['contact' => $email, 'code' => $code[1] === '000000' ? '111111' : '000000'])['error']);
        $token = $this->call('store_verify', ['contact' => $email, 'code' => $code[1]])['customer_token'];
        $orders = $this->call('store_find_orders', [], $token)['orders'];

        self::assertSame([self::number($mine)], array_column($orders, 'order'));
        self::assertSame('pending', $orders[0]['status']);
        self::assertSame('not_found', $this->call('store_get_order', ['order' => self::number($other)], $token)['error']);
        self::assertEquals(['amount' => round((float) $mine->getPrice(), 2), 'currency' => (string) $mine->getCurrency()], $this->call('store_get_order', ['order' => self::number($mine)], $token)['total']);
    }

    public function testAnAccountSeesTheOrdersOfItsEmailWithTrackingOfShipments(): void
    {
        $email = 'mcp-' . bin2hex(random_bytes(4)) . '@example.com';
        $account = $this->user($email);
        $token = StoreMcp::tokens()->issue('user:' . $account);
        $guest = $this->order($this->product('Wool Scarf', 25.0), self::checkoutAccount($email), 1, $email);
        $shipped = $this->order($this->product('Linen Dress', 40.0), $account, 1, $email);
        $shipment = null;

        foreach ($shipped->getShipmentCollection() as $candidate) {
            $shipment = $candidate->isSystem() ? $shipment : $candidate;
        }

        $shipment->setField('TRACKING_NUMBER', 'RA123456789RU');
        $shipment->setField('DEDUCTED', 'Y');
        self::assertTrue($shipped->save()->isSuccess());

        self::assertEqualsCanonicalizing([self::number($guest), self::number($shipped)], array_column($this->call('store_find_orders', [], $token)['orders'], 'order'));
        $tracking = $this->call('store_get_tracking', ['order' => self::number($shipped)], $token);
        self::assertSame('RA123456789RU', $tracking['tracking_number']);
        self::assertSame('shipped', $tracking['status']);
        self::assertSame('not_found', $this->call('store_get_tracking', ['order' => self::number($guest)], $token)['error']);
        self::assertSame('Anna', $this->call('store_get_customer', [], $token)['first_name']);
    }

    public function testCancelsOnlyOrdersWithNothingPaidAfterConsent(): void
    {
        $userId = $this->user();
        $token = StoreMcp::tokens()->issue('user:' . $userId);
        $unpaid = $this->order($this->product('Linen Dress', 40.0), $userId, 1);

        self::assertSame('not_allowed', $this->call('store_cancel_order', ['order' => self::number($unpaid)], $token)['error']);
        self::assertSame('cancelled', $this->call('store_cancel_order', ['order' => self::number($unpaid), 'reason' => 'Changed my mind'], $token, true)['status']);
        $cancelled = Order::load($unpaid->getId());
        self::assertTrue($cancelled->isCanceled());
        self::assertStringContainsString('Changed my mind', (string) $cancelled->getField('REASON_CANCELED'));

        $paid = $this->order($this->product('Linen Dress', 40.0), $userId, 1);
        $paid->getPaymentCollection()->current()->setPaid('Y');
        self::assertTrue($paid->save()->isSuccess());
        self::assertSame('not_allowed', $this->call('store_cancel_order', ['order' => self::number($paid)], $token, true)['error']);
        self::assertFalse(Order::load($paid->getId())->isCanceled());
    }

    public function testPassesADeliveryChangeToTheShopOnceAndRefusesToolsTheOwnerDidNotAllow(): void
    {
        $userId = $this->user();
        $token = StoreMcp::tokens()->issue('user:' . $userId);
        $order = $this->order($this->product('Linen Dress', 40.0), $userId, 1);
        $arguments = ['order' => self::number($order), 'address' => 'Москва, Тверская 5'];

        $first = $this->call('store_change_delivery', $arguments, $token, true);
        $again = $this->call('store_change_delivery', $arguments, $token, true);
        $requests = array_values(array_filter(self::$mails, static fn (array $mail): bool => $mail['TO'] === self::SHOP));

        self::assertSame($first, $again);
        self::assertSame(1, substr_count((string) Order::load($order->getId())->getField('COMMENTS'), 'Тверская 5'));
        self::assertCount(1, $requests, 'One email to the shop.');
        self::assertStringContainsString('Тверская 5', (string) $requests[0]['BODY']);
        // Returns are not allowed in the settings of the test.
        self::assertSame('not_allowed', $this->call('store_create_return', ['order' => self::number($order), 'items' => [], 'reason' => 'Too big'], $token, true)['error']);
    }

    public function testChecksAvailabilityOfCatalogProducts(): void
    {
        $name = 'Trail Runner ' . bin2hex(random_bytes(3));
        $product = $this->product($name, 49.95);
        $found = $this->call('store_check_availability', ['query' => $name])['products'];

        self::assertSame([(string) $product], array_column($found, 'product_id'));
        self::assertTrue($found[0]['in_stock']);
        self::assertEquals(49.95, $found[0]['price']['amount']);
        self::assertSame([(string) $product], array_column($this->call('store_check_availability', ['product_id' => $product])['products'], 'product_id'));
        self::assertSame([], $this->call('store_check_availability', ['query' => 'no-such-' . bin2hex(random_bytes(3))])['products']);
    }

    public function testGivesTheChatTokenToASignedInCustomerOnly(): void
    {
        global $USER;
        $userId = $this->user();

        self::assertNull(StoreMcp::customerToken(self::SITE), 'A guest.');

        self::assertTrue($USER->Authorize($userId));

        try {
            $token = StoreMcp::customerToken(self::SITE);
            self::assertNotNull($token);
            self::assertSame('user:' . $userId, StoreMcp::tokens()->verify($token));
        } finally {
            $USER->Logout();
        }
    }

    public function testConnectsTheServerToTheBotOfTheProjectAndDisconnectsItWhenTurnedOff(): void
    {
        $this->platform->responder = static fn (Request $request): ?Response => (string) parse_url($request->getUrl(), PHP_URL_PATH) === '/api/v1/chat/store-mcp' && $request->getMethod() === 'PUT'
            ? new Response(200, ['content-type' => 'application/json'], '{"status":"ok","error":null,"tools":["store_get_order"]}')
            : null;

        StoreMcp::connect();
        $put = $this->requests('PUT', '/api/v1/chat/store-mcp');

        self::assertCount(1, $put);
        self::assertStringStartsWith('https://', $put[0]['body']['url']);
        self::assertStringContainsString('/bitrix/services/onetrace.connector/mcp.php?site=' . self::SITE, $put[0]['body']['url']);
        self::assertSame(StoreMcp::key(), $put[0]['body']['key']);
        self::assertSame('bitrix', $put[0]['body']['platform']);
        self::assertSame(['store_get_order'], StoreMcp::status(self::SITE)['tools']);

        Config::set('store_mcp', 'N', self::SITE);
        StoreMcp::connect();

        self::assertSame([['url' => $put[0]['body']['url']]], array_column($this->requests('DELETE', '/api/v1/chat/store-mcp'), 'body'));
        self::assertNull(StoreMcp::status(self::SITE));
    }

    /**
     * The account Bitrix makes for a checkout without login: another login with the same email.
     */
    private static function checkoutAccount(string $email): int
    {
        $user = new \CUser();
        $id = (int) $user->Add(['LOGIN' => 'buyer_' . bin2hex(random_bytes(4)), 'EMAIL' => $email, 'NAME' => 'Anna', 'PASSWORD' => 'Passw0rd!' . bin2hex(random_bytes(4)), 'ACTIVE' => 'Y', 'LID' => self::SITE]);
        self::assertGreaterThan(0, $id, (string) $user->LAST_ERROR);

        return $id;
    }

    private static function number(Order $order): string
    {
        return (string) $order->getField('ACCOUNT_NUMBER');
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{status: int, data: mixed}
     */
    private function rpc(string $method, array $params = [], ?string $key = null): array
    {
        $response = StoreMcp::server(self::SITE)->handle('POST', ['Authorization' => 'Bearer ' . ($key ?? StoreMcp::key())], (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]));

        return ['status' => $response['status'], 'data' => json_decode($response['body'], true)];
    }

    /**
     * structuredContent of a tool call.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function call(string $tool, array $arguments = [], ?string $token = null, bool $confirmed = false): array
    {
        $meta = array_filter(['onetrace/customer_token' => $token, 'onetrace/confirmed' => $confirmed ?: null, 'onetrace/idempotency_key' => $confirmed ? 'k-' . $tool . '-' . json_encode($arguments) : null]);

        return $this->rpc('tools/call', ['name' => $tool, 'arguments' => $arguments, '_meta' => $meta])['data']['result']['structuredContent'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requests(string $method, string $path): array
    {
        return array_values(array_filter($this->platform->requests, static fn (array $request): bool => $request['method'] === $method && $request['path'] === $path));
    }
}
