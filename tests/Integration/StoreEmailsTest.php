<?php

declare(strict_types=1);

namespace Onetrace\Connector\Tests\Integration;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\EventResult;
use Bitrix\Main\Mail\Internal\EventMessageTable;
use Bitrix\Main\Mail\Internal\EventTable;
use Bitrix\Sale\Order;
use Bitrix\Sale\Shipment;
use OneTrace\Http\Request;
use OneTrace\Http\Response;
use Onetrace\Connector\Config;
use Onetrace\Connector\Events;
use Onetrace\Connector\Handler\Mail;
use Onetrace\Connector\StoreEmails;
use Onetrace\Connector\Tests\TestCase;

/**
 * Store emails through OneTrace: Bitrix mail events relayed as they are, order emails from OneTrace templates, the
 * native email for everything the platform did not accept, and the emails the module sends itself.
 */
final class StoreEmailsTest extends TestCase
{
    private const SHOP = 'orders@shop.example.com';

    /** @var list<array<string, mixed>> emails Bitrix sent natively (main:OnBeforeMailSend) */
    private static array $native = [];

    private static bool $listening = false;

    /** @var array{relay: bool, integration_id: int|null, templates: array<string, bool>} */
    private array $settings = ['relay' => false, 'integration_id' => null, 'templates' => []];

    /** @var (callable(array<string, mixed>): ?int)|null status of POST /emails by its body; null — 202 */
    private $refuse;

    /** @var list<int> email templates turned off by a test */
    private array $disabled = [];

    protected function setUp(): void
    {
        parent::setUp();
        Events::register();
        Config::set('delivered_statuses', 'F', self::SITE);
        Option::set('sale', 'order_email', self::SHOP);
        Application::getConnection()->queryExecute("UPDATE b_event SET SUCCESS_EXEC = 'Y' WHERE SUCCESS_EXEC = 'N'");
        self::$native = [];
        $this->refuse = null;

        // Bitrix's own sending is recorded (with the changes of the handlers before, as Bitrix merges them) and stopped:
        // the container has no mail server.
        if (!self::$listening) {
            self::$listening = true;
            EventManager::getInstance()->addEventHandler('main', 'OnBeforeMailSend', static function (\Bitrix\Main\Event $event): EventResult {
                $params = $event->getParameter(0);

                foreach ($event->getResults() as $result) {
                    if ($result->getType() !== EventResult::ERROR) {
                        $params = array_merge($params, (array) $result->getParameters());
                    }
                }

                self::$native[] = $params;

                return new EventResult(EventResult::ERROR);
            }, false, 10000);
        }

        $this->platform->responder = function (Request $request): ?Response {
            $path = (string) parse_url($request->getUrl(), PHP_URL_PATH);

            if ($path === '/api/v1/emails/settings') {
                return new Response(200, ['content-type' => 'application/json'], (string) json_encode($this->settings));
            }

            if ($path === '/api/v1/emails') {
                $status = $this->refuse !== null ? ($this->refuse)((array) json_decode((string) $request->getBody(), true)) : null;

                return $status !== null
                    ? new Response($status, ['content-type' => 'application/json'], '{"message":"Refused"}')
                    : new Response(202, ['content-type' => 'application/json'], '{"id":1,"status":"queued"}');
            }

            return null;
        };
    }

    protected function tearDown(): void
    {
        foreach ($this->disabled as $id) {
            EventMessageTable::update($id, ['ACTIVE' => 'Y']);
        }

        Option::delete('sale', ['name' => 'order_email']);
        Option::set('main', 'all_bcc', '');
        parent::tearDown();
    }

    public function testEventTypesMapToKinds(): void
    {
        self::assertSame('order_placed', StoreEmails::kind('SALE_NEW_ORDER', self::SITE));
        self::assertSame('order_paid', StoreEmails::kind('SALE_ORDER_PAID', self::SITE));
        self::assertSame('order_cancelled', StoreEmails::kind('SALE_ORDER_CANCEL', self::SITE));
        self::assertSame('order_shipped', StoreEmails::kind('SALE_ORDER_TRACKING_NUMBER', self::SITE));
        self::assertSame('order_shipped', StoreEmails::kind('SALE_ORDER_DELIVERY', self::SITE));
        self::assertSame('order_delivered', StoreEmails::kind('SALE_STATUS_CHANGED_F', self::SITE), 'A status of the "Delivered" setting.');
        self::assertSame('order_note', StoreEmails::kind('SALE_STATUS_CHANGED_N', self::SITE));
        self::assertSame('password_reset', StoreEmails::kind('USER_PASS_REQUEST', self::SITE));
        self::assertSame('email_verification', StoreEmails::kind('NEW_USER_CONFIRM', self::SITE));
        self::assertSame('account_new', StoreEmails::kind('USER_INFO', self::SITE));
        self::assertSame('login_code', StoreEmails::kind('USER_CODE_REQUEST', self::SITE));
        self::assertSame('other', StoreEmails::kind('SALE_RECURRING_CANCEL', self::SITE));
        self::assertNull(StoreEmails::kind('NEW_FORUM_MESSAGE', self::SITE), 'Not a store email.');
    }

    public function testSettingsAreCachedAndReadAgainAfterSaving(): void
    {
        $this->settings = ['relay' => true, 'integration_id' => 7, 'templates' => ['order_paid' => true]];

        self::assertSame($this->settings, StoreEmails::settings(self::SITE));
        self::assertSame($this->settings, StoreEmails::settings(self::SITE));
        self::assertCount(1, $this->settingsRequests());

        StoreEmails::forget();
        $this->settings['relay'] = false;

        self::assertFalse(StoreEmails::settings(self::SITE)['relay']);
        self::assertCount(2, $this->settingsRequests());

        // Unavailable: the shop sends natively and does not ask on every email.
        StoreEmails::forget();
        $this->platform->responder = static fn (): Response => new Response(503, [], '{}');

        self::assertNull(StoreEmails::settings(self::SITE));
        self::assertNull(StoreEmails::settings(self::SITE));
        self::assertCount(3, $this->settingsRequests());
    }

    public function testATextEmailIsRelayedAsHtmlAndTextAndBitrixDoesNotSendIt(): void
    {
        $this->settings['relay'] = true;
        $this->settings['integration_id'] = 7;
        $id = $this->mailEvent('USER_PASS_REQUEST', ['EMAIL' => 'anna@example.com', 'NAME' => 'Anna', 'LAST_NAME' => 'Schmidt', 'LOGIN' => 'anna', 'URL_LOGIN' => 'anna', 'CHECKWORD' => 'secret123', 'USER_ID' => 5, 'STATUS' => 'active', 'MESSAGE' => '']);
        $this->processMailEvents();

        $emails = $this->platform->emails();

        self::assertCount(1, $emails);
        self::assertSame('anna@example.com', $emails[0]['to']);
        self::assertSame('password_reset', $emails[0]['kind']);
        self::assertSame('ru', $emails[0]['language']);
        self::assertSame(7, $emails[0]['integration_id']);
        self::assertStringContainsString('Запрос на смену пароля', $emails[0]['subject']);
        self::assertStringContainsString('USER_CHECKWORD=secret123', $emails[0]['text']);
        self::assertMatchesRegularExpression('#<a href="http://[^"]*USER_CHECKWORD=secret123[^"]*">#', $emails[0]['html']);
        self::assertStringContainsString('<br', $emails[0]['html']);
        self::assertArrayNotHasKey('order', $emails[0]);
        self::assertNotSame('', $emails[0]['_key'], 'With an idempotency key.');
        self::assertSame([], self::$native, 'Bitrix does not send it.');
        self::assertSame('Y', EventTable::getRowById($id)['SUCCESS_EXEC'], 'The event is sent.');
    }

    public function testAnOrderEmailComesFromTheTemplateAndTheShopCopyStaysWithBitrix(): void
    {
        $this->settings = ['relay' => true, 'integration_id' => null, 'templates' => ['order_placed' => true]];
        $email = 'anna-' . bin2hex(random_bytes(4)) . '@example.com';
        $order = $this->order($this->product('Linen Dress', 40.0), $this->user($email), 2);
        $this->processMailEvents();

        $emails = $this->emailsOf('order_placed');
        $native = self::nativeOf('SALE_NEW_ORDER');

        self::assertCount(1, $emails);
        self::assertSame($email, $emails[0]['to']);
        self::assertSame('ru', $emails[0]['language']);
        self::assertArrayNotHasKey('html', $emails[0], 'The template builds the email.');
        self::assertSame((string) ($order->getField('ACCOUNT_NUMBER') ?: $order->getId()), $emails[0]['order']['number']);
        self::assertSame(80.0, $emails[0]['order']['amount']);
        self::assertSame('Linen Dress', $emails[0]['order']['products'][0]['product_name']);
        self::assertSame(80.0, $emails[0]['order']['products'][0]['total']);
        self::assertStringStartsWith('http', $emails[0]['order']['url']);
        self::assertSame('Anna', $emails[0]['traits']['first_name']);

        self::assertSame([], $this->emailsTo(self::SHOP), 'Copies to the shop addresses never go through OneTrace, even with the relay on.');
        self::assertCount(1, $native);
        self::assertSame(self::SHOP, $native[0]['TO'], 'Only the shop copy goes natively.');
        self::assertArrayNotHasKey('BCC', $native[0]['HEADER']);
        self::assertStringNotContainsString($email, (string) json_encode($native[0]['HEADER']));
    }

    public function testWithoutRelayOnlyTheTemplateKindGoesThroughOneTrace(): void
    {
        $this->settings = ['relay' => false, 'integration_id' => null, 'templates' => ['order_placed' => true]];
        $email = 'anna-' . bin2hex(random_bytes(4)) . '@example.com';
        $this->order($this->product(), $this->user($email), 1);
        $this->processMailEvents();

        self::assertCount(1, $this->emailsOf('order_placed'));
        self::assertSame([], $this->emailsTo(self::SHOP));
        self::assertSame([], $this->emailsOf('order_note'), 'The status email goes natively without the relay.');
        $native = self::nativeOf('SALE_NEW_ORDER');

        self::assertCount(1, $native);
        self::assertSame(self::SHOP, $native[0]['TO'], 'Only the shop copy goes natively.');
        self::assertStringNotContainsString($email, (string) json_encode($native[0]['HEADER']));
    }

    public function testBitrixSendsWhatThePlatformRefused(): void
    {
        $this->settings['relay'] = true;
        $email = 'anna-' . bin2hex(random_bytes(4)) . '@example.com';
        $userId = $this->user($email);

        // Unavailable: the whole email natively, to the customer and the shop.
        $this->refuse = static fn (): int => 503;
        $this->order($this->product(), $userId, 1);
        $this->processMailEvents();

        $native = self::nativeOf('SALE_NEW_ORDER');

        self::assertCount(1, $native);
        self::assertSame($email, $native[0]['TO']);
        self::assertSame(self::SHOP, $native[0]['HEADER']['BCC']);

        // Accepted: the customer through OneTrace, the shop copy natively.
        self::$native = [];
        $this->platform->requests = [];
        $this->refuse = null;
        $this->order($this->product(), $userId, 1);
        $this->processMailEvents();

        $native = self::nativeOf('SALE_NEW_ORDER');

        self::assertCount(1, $this->emailsOf('order_placed'));
        self::assertSame($email, $this->emailsOf('order_placed')[0]['to']);
        self::assertSame([], $this->emailsTo(self::SHOP));
        self::assertCount(1, $native);
        self::assertSame(self::SHOP, $native[0]['TO']);
        self::assertArrayNotHasKey('BCC', $native[0]['HEADER']);

        // No relay and no templates: the module does not touch the emails.
        self::$native = [];
        $this->platform->requests = [];
        $this->settings = ['relay' => false, 'integration_id' => null, 'templates' => []];
        StoreEmails::forget();
        $this->order($this->product(), $userId, 1);
        $this->processMailEvents();

        self::assertSame([], $this->platform->emails());
        self::assertCount(1, self::nativeOf('SALE_NEW_ORDER'));
        self::assertSame($email, self::nativeOf('SALE_NEW_ORDER')[0]['TO']);
    }

    public function testTheNativeRestHasEveryAddressOnceWithCopiesOfAllEmails(): void
    {
        $this->settings['relay'] = true;
        $userId = $this->user();

        // Another address in "Copies of all emails": the shop copy in To, the copy of all emails in BCC.
        Option::set('main', 'all_bcc', 'copies@shop.example.com');
        $this->order($this->product(), $userId, 1);
        $this->processMailEvents();

        $native = self::nativeOf('SALE_NEW_ORDER');

        self::assertCount(1, $native);
        self::assertSame(self::SHOP, $native[0]['TO']);
        self::assertSame('copies@shop.example.com', $native[0]['HEADER']['BCC']);

        // The shop address is the copy of all emails too: in To once, not in BCC again.
        self::$native = [];
        Option::set('main', 'all_bcc', strtoupper(self::SHOP));
        $this->order($this->product(), $userId, 1);
        $this->processMailEvents();

        $native = self::nativeOf('SALE_NEW_ORDER');

        self::assertCount(1, $native);
        self::assertSame(self::SHOP, $native[0]['TO']);
        self::assertArrayNotHasKey('BCC', $native[0]['HEADER']);

        // Only the copy of all emails is left (the customer went through OneTrace): it becomes To, not To and BCC.
        self::$native = [];
        Option::set('main', 'all_bcc', 'copies@shop.example.com');
        $this->mailEvent('USER_PASS_REQUEST', ['EMAIL' => 'anna@example.com', 'NAME' => 'Anna', 'LOGIN' => 'anna', 'URL_LOGIN' => 'anna', 'CHECKWORD' => 'secret', 'USER_ID' => 5, 'STATUS' => 'active', 'MESSAGE' => '']);
        $this->processMailEvents();

        $native = self::nativeOf('USER_PASS_REQUEST');

        self::assertCount(1, $this->emailsTo('anna@example.com'));
        self::assertCount(1, $native);
        self::assertSame('copies@shop.example.com', $native[0]['TO']);
        self::assertArrayNotHasKey('BCC', $native[0]['HEADER']);
    }

    public function testATemplateEmailThePlatformRefusedGoesNatively(): void
    {
        $this->settings['templates'] = ['order_placed' => true];
        $this->refuse = static fn (): int => 422;
        $email = 'anna-' . bin2hex(random_bytes(4)) . '@example.com';
        $this->order($this->product(), $this->user($email), 1);
        $this->processMailEvents();

        $native = self::nativeOf('SALE_NEW_ORDER');

        self::assertCount(1, $this->emailsOf('order_placed'));
        self::assertCount(1, $native, 'The email of Bitrix as it is: to the customer with the shop copy.');
        self::assertSame($email, $native[0]['TO']);
        self::assertSame(self::SHOP, $native[0]['HEADER']['BCC']);
    }

    public function testARefundIsSentFromTheTemplateByTheModuleOncePerPayment(): void
    {
        $this->settings['templates'] = ['order_refunded' => true];
        $order = $this->order($this->product('Linen Dress', 40.0), $this->user(), 2);
        $payment = $order->getPaymentCollection()->current();
        $payment->setPaid('Y');
        self::save($order);
        $payment->setReturn('Y');
        self::save($order);
        $order->setField('COMMENTS', 'Refunded');
        self::save($order);
        $this->flush();

        $refunds = $this->emailsOf('order_refunded');

        self::assertCount(1, $refunds);
        self::assertSame(80.0, $refunds[0]['order']['refund_amount']);
        self::assertStringContainsString('-refunded-', $refunds[0]['_key']);
    }

    public function testAnAttachmentOfAnotherTypeKeepsTheEmailWithBitrix(): void
    {
        $this->settings['relay'] = true;
        $pdf = \CFile::SaveFile(['name' => 'invoice.pdf', 'type' => 'application/pdf', 'content' => '%PDF-1.4 test', 'MODULE_ID' => 'main'], 'onetrace');
        $this->mailEvent('USER_PASS_CHANGED', ['EMAIL' => 'anna@example.com', 'NAME' => 'Anna', 'LOGIN' => 'anna', 'USER_ID' => 5, 'STATUS' => 'active', 'MESSAGE' => ''], [(int) $pdf]);
        $this->processMailEvents();

        $emails = $this->platform->emails();

        self::assertCount(1, $emails);
        self::assertSame('other', $emails[0]['kind']);
        self::assertSame([['name' => 'invoice.pdf', 'type' => 'application/pdf', 'content' => base64_encode('%PDF-1.4 test')]], $emails[0]['attachments']);
        self::assertSame([], self::$native);

        $this->platform->requests = [];
        $zip = \CFile::SaveFile(['name' => 'archive.zip', 'type' => 'application/zip', 'content' => 'PK', 'MODULE_ID' => 'main'], 'onetrace');
        $this->mailEvent('USER_PASS_CHANGED', ['EMAIL' => 'anna@example.com', 'NAME' => 'Anna', 'LOGIN' => 'anna', 'USER_ID' => 5, 'STATUS' => 'active', 'MESSAGE' => ''], [(int) $zip]);
        $this->processMailEvents();

        self::assertSame([], $this->platform->emails());
        self::assertCount(1, self::$native);
    }

    public function testTheTrackingNumberEmailOfBitrixComesFromTheTemplateWithTheShipment(): void
    {
        $this->settings['templates'] = ['order_shipped' => true];
        $order = $this->order($this->product('Wool Socks', 10.0), $this->user(), 3);
        $shipment = self::shipment($order);
        $shipment->setField('TRACKING_NUMBER', 'RA123456789RU');
        $shipment->setField('DEDUCTED', 'Y');
        self::save($order);
        $this->processMailEvents();
        $this->flush();

        $shipped = $this->emailsOf('order_shipped');

        self::assertCount(1, $shipped, 'The native email went through the template; the module sends no second one.');
        self::assertSame('RA123456789RU', $shipped[0]['order']['tracking_number']);
        self::assertSame(3, $shipped[0]['order']['products'][0]['quantity']);
        self::assertArrayNotHasKey('carrier', $shipped[0]['order'], '"Без доставки" is not a carrier.');
    }

    public function testShippedAndDeliveredWithoutAnEmailOfBitrixAreSentByTheModuleOnce(): void
    {
        $this->settings['templates'] = ['order_shipped' => true, 'order_delivered' => true];
        $this->disable('SALE_ORDER_TRACKING_NUMBER');
        $this->disable('SALE_STATUS_CHANGED_F');
        $order = $this->order($this->product('Wool Socks', 10.0), $this->user(), 1);
        $shipment = self::shipment($order);
        $shipment->setField('TRACKING_NUMBER', 'RA987654321RU');
        $shipment->setField('DEDUCTED', 'Y');
        self::save($order);
        $order->setField('STATUS_ID', 'F');
        self::save($order);
        $order->setField('COMMENTS', 'Thank you');
        self::save($order);
        $this->processMailEvents();
        $this->flush();
        $this->flush();

        $shipped = $this->emailsOf('order_shipped');
        $delivered = $this->emailsOf('order_delivered');

        self::assertCount(1, $shipped);
        self::assertSame('RA987654321RU', $shipped[0]['order']['tracking_number']);
        self::assertStringContainsString('-shipped-' . $shipment->getId(), $shipped[0]['_key']);
        self::assertCount(1, $delivered);
        self::assertSame((string) ($order->getField('ACCOUNT_NUMBER') ?: $order->getId()), $delivered[0]['order']['number']);
    }

    public function testTheDeliveredStatusEmailOfBitrixComesFromTheTemplate(): void
    {
        $this->settings['templates'] = ['order_delivered' => true];
        $order = $this->order($this->product(), $this->user(), 1);
        $order->setField('STATUS_ID', 'F');
        self::save($order);
        $this->processMailEvents();
        $this->flush();

        self::assertCount(1, $this->emailsOf('order_delivered'), 'From the native status email, not a second one from the module.');
        self::assertSame([], self::nativeOf('SALE_STATUS_CHANGED_F'));
    }

    public function testATextBodyBecomesSafeHtml(): void
    {
        self::assertSame(
            '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5">&lt;b&gt; <a href="https://shop.example.com/a?x=1&amp;y=2">https://shop.example.com/a?x=1&amp;y=2</a><br />' . "\n" . 'Bye</div>',
            Mail::textToHtml("<b> https://shop.example.com/a?x=1&y=2\nBye")
        );
    }

    /**
     * A mail event of the site as Bitrix modules send it (CEvent::Send).
     *
     * @param array<string, mixed> $fields
     * @param list<int> $files
     */
    private function mailEvent(string $name, array $fields, array $files = []): int
    {
        $result = \Bitrix\Main\Mail\Event::send(['EVENT_NAME' => $name, 'LID' => self::SITE, 'C_FIELDS' => $fields, 'DUPLICATE' => 'Y', 'LANGUAGE_ID' => 'ru', 'FILE' => $files]);
        self::assertTrue($result->isSuccess());

        return (int) $result->getId();
    }

    /** Sends the waiting mail events, as the agent or a hit of the site does. */
    private function processMailEvents(): void
    {
        for ($i = 0; $i < 20 && EventTable::getCount(['=SUCCESS_EXEC' => 'N']) > 0; ++$i) {
            \Bitrix\Main\Mail\EventManager::executeEvents();
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function emailsOf(string $kind): array
    {
        return array_values(array_filter($this->platform->emails(), static fn (array $email): bool => $email['kind'] === $kind));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function emailsTo(string $address): array
    {
        return array_values(array_filter($this->platform->emails(), static fn (array $email): bool => strcasecmp((string) $email['to'], $address) === 0));
    }

    /**
     * Native emails of a mail event type.
     *
     * @return list<array<string, mixed>>
     */
    private static function nativeOf(string $eventName): array
    {
        return array_values(array_filter(self::$native, static fn (array $email): bool => ($email['HEADER']['X-EVENT_NAME'] ?? '') === $eventName));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function settingsRequests(): array
    {
        return array_values(array_filter($this->platform->requests, static fn (array $request): bool => $request['path'] === '/api/v1/emails/settings'));
    }

    private function disable(string $eventName): void
    {
        foreach (EventMessageTable::getList(['select' => ['ID'], 'filter' => ['=EVENT_NAME' => $eventName, '=ACTIVE' => 'Y']])->fetchAll() as $row) {
            EventMessageTable::update((int) $row['ID'], ['ACTIVE' => 'N']);
            $this->disabled[] = (int) $row['ID'];
        }
    }

    private static function shipment(Order $order): Shipment
    {
        foreach ($order->getShipmentCollection() as $shipment) {
            if (!$shipment->isSystem()) {
                return $shipment;
            }
        }

        self::fail('No shipment.');
    }

    private static function save(Order $order): void
    {
        $result = $order->save();
        self::assertTrue($result->isSuccess(), implode('; ', $result->getErrorMessages()));
    }
}
