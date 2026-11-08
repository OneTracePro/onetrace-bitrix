<?php

declare(strict_types=1);

namespace Onetrace\Connector\Tests\Integration;

use Bitrix\Catalog\GroupTable;
use Bitrix\Catalog\Model\Price;
use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\EventResult;
use Bitrix\Sale\Delivery\Services\Manager as Deliveries;
use Bitrix\Sale\Internals\OrderPropsGroupTable;
use Bitrix\Sale\Internals\OrderPropsTable;
use Bitrix\Sale\Internals\PaySystemActionTable;
use Bitrix\Sale\Location\LocationTable;
use Bitrix\Sale\Order;
use Bitrix\Sale\PaySystem\Manager as PaySystems;
use OneTrace\Http\Request;
use OneTrace\Http\Response;
use OneTrace\StoreMcp\Context;
use OneTrace\StoreMcp\OrderInput;
use Onetrace\Connector\Config;
use Onetrace\Connector\StoreMcp;
use Onetrace\Connector\StoreMcp\Orders;
use Onetrace\Connector\Tests\TestCase;

/**
 * Orders from AI shopping assistants through the store MCP (contract v2): the quote by the checkout rules of the
 * store (catalog prices, delivery services of the location, payment systems), the order of a confirmed customer or
 * of a guest confirmed by a code, once per idempotency key, with the assistant in the order and its events.
 */
final class StoreMcpOrdersTest extends TestCase
{
    /** A city of the locations of the store (made by the test in Russia). */
    private const CITY = 'Тестоград';

    private const CITY_LOCATION = 'onetrace_test_city';

    private const ZIP = '274999';

    /** @var list<array<string, mixed>> emails Bitrix would send (main:OnBeforeMailSend), stopped there */
    private static array $mails = [];

    private static bool $listening = false;

    /** @var array{courier: int, local: int, bill: int, cash: int, properties: list<int>} */
    private static array $checkout = [];

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('store_mcp', 'Y', self::SITE);
        Config::set('store_mcp_tools', implode(',', StoreMcp::tools()), self::SITE);
        Config::set('payment_page', '', self::SITE);
        StoreMcp::installMailEvents();
        self::$checkout = self::checkout();
        self::switchCheckout(true);
        self::$mails = [];

        if (!self::$listening) {
            self::$listening = true;
            EventManager::getInstance()->addEventHandler('main', 'OnBeforeMailSend', static function (\Bitrix\Main\Event $event): EventResult {
                self::$mails[] = $event->getParameter(0);

                return new EventResult(EventResult::ERROR);
            }, false, 10000);
        }

        $this->platform->responder = static fn (Request $request): ?Response => str_ends_with((string) parse_url($request->getUrl(), PHP_URL_PATH), '/emails/settings')
            ? new Response(403, ['content-type' => 'application/json'], '{"message":"No"}')
            : null;
    }

    protected function tearDown(): void
    {
        Config::set('store_mcp', 'N', self::SITE);
        Config::set('store_mcp_tools', '', self::SITE);
        Option::delete('sale', ['name' => 'allow_guest_order_view']);
        Option::delete('sale', ['name' => 'allow_guest_order_view_status']);
        self::switchCheckout(false);
        parent::tearDown();
    }

    public function testAnnouncesContractVersionTwoAndListsOrderToolsTheOwnerAllowed(): void
    {
        $init = $this->rpc('initialize')['result'];
        $tools = array_column($this->rpc('tools/list')['result']['tools'], 'name');

        self::assertSame(['version' => 2], $init['capabilities']['experimental']['onetrace/store']);
        self::assertContains('store_quote_order', $tools);
        self::assertContains('store_create_order', $tools);

        Config::set('store_mcp_tools', implode(',', StoreMcp::READ_TOOLS), self::SITE);

        self::assertNotContains('store_create_order', array_column($this->rpc('tools/list')['result']['tools'], 'name'), 'Orders are off by default.');
        self::assertSame('not_allowed', $this->call('store_quote_order', ['items' => [['product_id' => '1', 'quantity' => 1]]])['error']);
    }

    public function testQuotesTheProductsWithTheDeliveryOfTheAddressAndThePaymentMethods(): void
    {
        $dress = $this->product('Linen Dress', 40.0);
        $shirt = $this->product('Polo Shirt', 30.0);
        [$offer] = $this->offers($shirt, [[5, 'Y']], 25.0);
        $soldOut = $this->product('Sold Out Hat', 10.0);
        \Bitrix\Catalog\Model\Product::update($soldOut, ['QUANTITY' => 0, 'QUANTITY_TRACE' => 'Y', 'CAN_BUY_ZERO' => 'N']);

        $quote = $this->call('store_quote_order', [
            'items' => [['product_id' => (string) $dress, 'quantity' => 2], ['product_id' => (string) $offer, 'quantity' => 1], ['product_id' => (string) $shirt, 'quantity' => 1], ['product_id' => (string) $soldOut, 'quantity' => 1], ['product_id' => '999999999', 'quantity' => 1]],
            'shipping' => ['country' => 'RU', 'city' => self::CITY],
            'shipping_method' => (string) self::$checkout['local'],
        ]);
        $methods = array_column($quote['shipping_methods'], null, 'id');

        self::assertSame([(string) $dress, (string) $offer], array_column($quote['items'], 'product_id'));
        self::assertEquals(['amount' => 40.0, 'currency' => 'RUB'], $quote['items'][0]['price']);
        self::assertEquals(['amount' => 80.0, 'currency' => 'RUB'], $quote['items'][0]['total']);
        self::assertSame('Linen Dress', $quote['items'][0]['name']);
        self::assertEqualsCanonicalizing([(string) $shirt, (string) $soldOut, '999999999'], $quote['unavailable'], 'A product with offers, a sold out and an unknown one.');
        self::assertEquals(['amount' => 105.0, 'currency' => 'RUB'], $quote['subtotal']);
        self::assertEquals(['amount' => 0.0, 'currency' => 'RUB'], $quote['discount']);
        self::assertArrayHasKey((string) self::$checkout['courier'], $methods);
        self::assertEquals(['id' => (string) self::$checkout['local'], 'name' => 'OneTrace local courier', 'price' => ['amount' => 150.0, 'currency' => 'RUB'], 'min_days' => 1, 'max_days' => 2], $methods[(string) self::$checkout['local']]);
        self::assertSame((string) self::$checkout['local'], $quote['shipping_method']);
        self::assertEquals(['amount' => 150.0, 'currency' => 'RUB'], $quote['shipping_price']);
        self::assertEquals(['amount' => 255.0, 'currency' => 'RUB'], $quote['total']);
        $payments = array_column($quote['payment_methods'], 'name', 'id');
        self::assertSame('OneTrace bill', $payments[(string) self::$checkout['bill']] ?? null);
        self::assertArrayHasKey((string) self::$checkout['cash'], $payments);
        self::assertArrayNotHasKey((string) PaySystems::getInnerPaySystemId(), $payments, 'Not the inner account.');

        $byZip = $this->call('store_quote_order', ['items' => [['product_id' => (string) $dress, 'quantity' => 1]], 'shipping' => ['country' => 'RU', 'postcode' => self::ZIP]]);
        self::assertContains((string) self::$checkout['local'], array_column($byZip['shipping_methods'], 'id'), 'The location of the ZIP code.');

        $elsewhere = $this->call('store_quote_order', ['items' => [['product_id' => (string) $dress, 'quantity' => 1]], 'shipping' => ['country' => 'DE', 'city' => 'Berlin']]);
        $ids = array_column($elsewhere['shipping_methods'], 'id');

        self::assertContains((string) self::$checkout['courier'], $ids);
        self::assertNotContains((string) self::$checkout['local'], $ids, 'Restricted to the location of the city.');
        self::assertContains($elsewhere['shipping_method'], $ids, 'The first available one.');
    }

    public function testPlacesTheOrderOfAConfirmedCustomerOnceAndTagsItWithTheAssistant(): void
    {
        $email = 'mcp-order-' . bin2hex(random_bytes(4)) . '@example.com';
        $userId = $this->user($email);
        $token = StoreMcp::tokens()->issue('user:' . $userId);
        $product = $this->product('Linen Dress', 40.0);
        $arguments = $this->arguments($product, 2, (string) self::$checkout['courier'], (string) self::$checkout['bill']) + ['comment' => 'Ring the bell'];
        $key = 'assistant-' . bin2hex(random_bytes(4));

        self::assertEquals(['amount' => 380.0, 'currency' => 'RUB'], $this->call('store_quote_order', $arguments)['total']);
        self::assertSame('not_allowed', $this->call('store_create_order', $arguments, $token)['error'], 'Without the consent of the customer.');

        $placed = $this->call('store_create_order', $arguments, $token, $key);
        $again = $this->call('store_create_order', $arguments, $token, $key);
        $order = Order::loadByAccountNumber($placed['order']);

        self::assertSame($placed, $again);
        self::assertInstanceOf(Order::class, $order);
        self::assertSame('pending', $placed['status']);
        self::assertSame('pending', $placed['payment_status']);
        self::assertEquals(['amount' => 380.0, 'currency' => 'RUB'], $placed['total']);
        self::assertStringContainsString('/personal/order/payment/?ORDER_ID=' . urlencode(urlencode($placed['order'])) . '&PAYMENT_ID=', $placed['payment_url']);
        self::assertStringNotContainsString('HASH=', $placed['payment_url'], 'Guests may not view orders in this store.');
        self::assertSame($userId, (int) $order->getUserId());
        self::assertSame(1, Order::getList(['select' => ['ID'], 'filter' => ['=USER_ID' => $userId]])->getSelectedRowsCount(), 'One order for the key.');
        self::assertSame(self::$checkout['courier'], self::delivery($order));
        self::assertSame('Ring the bell', (string) $order->getField('USER_DESCRIPTION'));
        self::assertStringContainsString('ChatGPT', (string) $order->getField('COMMENTS'));

        $properties = $order->getPropertyCollection();
        self::assertSame('Anna Schmidt', $properties->getPayerName()?->getValue());
        self::assertSame($email, $properties->getUserEmail()?->getValue());
        self::assertSame('+79001234567', $properties->getPhone()?->getValue());
        self::assertSame('ул. Ленина, 5, кв. 7', $properties->getAddress()?->getValue());
        self::assertSame(self::ZIP, $properties->getDeliveryLocationZip()?->getValue());
        self::assertSame(self::CITY_LOCATION, $properties->getDeliveryLocation()?->getValue());

        // The cache of the answers is gone: the order is found by its idempotency key in the database.
        $repeat = Orders::create(OrderInput::create($arguments), new Context('user:' . $userId, 'ru', null, true, $key, null, Context::SOURCE_ASSISTANT, 'chatgpt'));
        self::assertEquals($placed, $repeat);

        $this->flush();
        $completed = $this->platform->events('order_completed');

        self::assertCount(1, $completed);
        self::assertSame('chatgpt', $completed[0]['properties']['ai_assistant']);
        self::assertSame(80.0, $completed[0]['properties']['subtotal']);
        self::assertContract($completed[0]);

        $payment = $order->getPaymentCollection()->current();
        $payment->setPaid('Y');
        self::assertTrue($order->save()->isSuccess());
        $this->flush();

        self::assertSame('chatgpt', $this->platform->events('order_paid')[0]['properties']['ai_assistant'], 'Paid later on the site or in the admin.');

        $this->order($product, $userId, 1, $email);
        self::assertArrayNotHasKey('ai_assistant', $this->lastCompleted(), 'Other orders are not tagged.');
    }

    public function testPlacesAGuestOrderAfterACodeToAnAddressWithoutAnAccount(): void
    {
        $email = 'mcp-guest-' . bin2hex(random_bytes(4)) . '@example.com';
        $product = $this->product('Wool Scarf', 25.0);
        Option::set('sale', 'allow_guest_order_view', 'Y');
        Option::set('sale', 'allow_guest_order_view_status', serialize(['N']));

        self::assertTrue($this->call('store_start_verification', ['contact' => $email, 'purpose' => 'order'])['sent']);
        $codes = array_values(array_filter(self::$mails, static fn (array $mail): bool => $mail['TO'] === $email));
        self::assertCount(1, $codes, 'A code to an address without an account.');
        self::assertSame(1, preg_match('/\b(\d{6})\b/', (string) $codes[0]['BODY'], $code));
        $token = $this->call('store_verify', ['contact' => $email, 'code' => $code[1]])['customer_token'];

        self::assertSame(['id' => null, 'email' => $email], $this->call('store_get_customer', [], $token));
        self::assertSame('auth_required', $this->call('store_find_orders', [], $token)['error'], 'A guest only places orders.');

        self::assertSame('invalid_input', $this->call('store_create_order', $this->arguments($product, 1, '0', null), $token, 'guest-' . bin2hex(random_bytes(4)))['error']);
        self::assertNull(\Bitrix\Main\UserTable::getRow(['select' => ['ID'], 'filter' => ['=EMAIL' => $email]]), 'No account for an order that was not placed.');

        $placed = $this->call('store_create_order', $this->arguments($product, 1, (string) self::$checkout['courier'], (string) self::$checkout['cash']), $token, 'guest-' . bin2hex(random_bytes(4)));
        $order = Order::loadByAccountNumber($placed['order']);
        $user = \Bitrix\Main\UserTable::getRow(['select' => ['EMAIL', 'NAME', 'LAST_NAME', 'PERSONAL_CITY', 'ACTIVE'], 'filter' => ['=ID' => (int) $order->getUserId()]]);

        self::assertSame('on_delivery', $placed['payment_status'], 'Cash on delivery.');
        self::assertNull($placed['payment_url']);
        self::assertSame(['EMAIL' => $email, 'NAME' => 'Anna', 'LAST_NAME' => 'Schmidt', 'PERSONAL_CITY' => self::CITY, 'ACTIVE' => 'Y'], $user, 'An account as at checkout.');
        self::assertSame($email, $order->getPropertyCollection()->getUserEmail()?->getValue());

        // A second order of the same guest goes to the same account; a bill is paid by the link with the access hash.
        $second = $this->call('store_create_order', $this->arguments($product, 2, (string) self::$checkout['courier'], (string) self::$checkout['bill']), $token, 'guest-' . bin2hex(random_bytes(4)));
        $secondOrder = Order::loadByAccountNumber($second['order']);

        self::assertSame((int) $order->getUserId(), (int) $secondOrder->getUserId());
        self::assertStringEndsWith('&HASH=' . $secondOrder->getHash(), $second['payment_url']);
    }

    public function testRefusesAnOrderAtAHigherPriceThanQuotedOrWithProductsGone(): void
    {
        $userId = $this->user();
        $token = StoreMcp::tokens()->issue('user:' . $userId);
        $product = $this->product('Linen Dress ' . bin2hex(random_bytes(3)), 40.0);
        $arguments = $this->arguments($product, 1, (string) self::$checkout['courier'], null);

        $this->call('store_quote_order', $arguments);
        $price = Price::getList(['select' => ['ID'], 'filter' => ['=PRODUCT_ID' => $product]])->fetch();
        self::assertTrue(Price::update((int) $price['ID'], ['PRICE' => 45.0, 'CURRENCY' => 'RUB', 'CATALOG_GROUP_ID' => (int) GroupTable::getRow(['select' => ['ID'], 'filter' => ['=BASE' => 'Y']])['ID']])->isSuccess());

        $changed = $this->call('store_create_order', $arguments, $token, 'price-' . bin2hex(random_bytes(4)));
        self::assertSame('invalid_input', $changed['error']);
        self::assertStringContainsString('Linen Dress', $changed['message']);

        self::assertEquals(['amount' => 345.0, 'currency' => 'RUB'], $this->call('store_quote_order', $arguments)['total'], 'Quoted again.');
        self::assertSame('pending', $this->call('store_create_order', $arguments, $token, 'price-' . bin2hex(random_bytes(4)))['status']);

        $method = $this->call('store_create_order', ['shipping_method' => '0'] + $arguments, $token, 'method-' . bin2hex(random_bytes(4)));
        self::assertSame('invalid_input', $method['error'], 'A delivery service that is not available.');

        (new \CIBlockElement())->Update($product, ['ACTIVE' => 'N']);
        $gone = $this->call('store_create_order', $arguments, $token, 'gone-' . bin2hex(random_bytes(4)));
        self::assertSame('invalid_input', $gone['error']);
        self::assertStringContainsString((string) $product, $gone['message']);
    }

    /**
     * Arguments of the order tools for a product: the delivery to a city of the location import.
     *
     * @return array<string, mixed>
     */
    private function arguments(int $product, int $quantity, string $shippingMethod, ?string $paymentMethod): array
    {
        return array_filter([
            'items' => [['product_id' => (string) $product, 'quantity' => $quantity]],
            'shipping' => ['first_name' => 'Anna', 'last_name' => 'Schmidt', 'phone' => '+79001234567', 'country' => 'RU', 'city' => self::CITY, 'postcode' => self::ZIP, 'address' => 'ул. Ленина, 5, кв. 7'],
            'shipping_method' => $shippingMethod,
            'payment_method' => $paymentMethod,
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function lastCompleted(): array
    {
        $this->flush();
        $completed = $this->platform->events('order_completed');

        return (array) (end($completed)['properties'] ?? []);
    }

    private static function delivery(Order $order): int
    {
        foreach ($order->getShipmentCollection() as $shipment) {
            if (!$shipment->isSystem()) {
                return (int) $shipment->getDeliveryId();
            }
        }

        return 0;
    }

    /**
     * The checkout of the test store, made once: a payer type with the properties of the standard checkout, two
     * delivery services (one only for the city) and two payment systems.
     *
     * @return array{courier: int, local: int, bill: int, cash: int}
     */
    private static function checkout(): array
    {
        if (self::$checkout !== []) {
            return self::$checkout;
        }

        $personType = \Bitrix\Sale\Internals\PersonTypeTable::getRow(['select' => ['ID'], 'filter' => ['=ACTIVE' => 'Y', '=PERSON_TYPE_SITE.SITE_ID' => self::SITE], 'order' => ['SORT' => 'ASC']]);
        $personTypeId = (int) ($personType['ID'] ?? 0) ?: (int) \CSalePersonType::Add(['LID' => self::SITE, 'NAME' => 'Физическое лицо', 'SORT' => 100, 'ACTIVE' => 'Y']);
        self::assertGreaterThan(0, $personTypeId);
        $group = OrderPropsGroupTable::getRow(['select' => ['ID'], 'filter' => ['=PERSON_TYPE_ID' => $personTypeId]]);
        $groupId = (int) ($group['ID'] ?? OrderPropsGroupTable::add(['PERSON_TYPE_ID' => $personTypeId, 'NAME' => 'Покупатель', 'SORT' => 100])->getId());
        $properties = [
            'FIO' => ['NAME' => 'Ф.И.О.', 'TYPE' => 'STRING', 'IS_PAYER' => 'Y', 'REQUIRED' => 'Y'],
            'EMAIL' => ['NAME' => 'E-Mail', 'TYPE' => 'STRING', 'IS_EMAIL' => 'Y', 'IS_PROFILE_NAME' => 'N'],
            'PHONE' => ['NAME' => 'Телефон', 'TYPE' => 'STRING', 'IS_PHONE' => 'Y'],
            'ZIP' => ['NAME' => 'Индекс', 'TYPE' => 'STRING', 'IS_ZIP' => 'Y'],
            'CITY' => ['NAME' => 'Город', 'TYPE' => 'STRING'],
            'LOCATION' => ['NAME' => 'Местоположение', 'TYPE' => 'LOCATION', 'IS_LOCATION' => 'Y', 'IS_LOCATION4TAX' => 'Y'],
            'ADDRESS' => ['NAME' => 'Адрес доставки', 'TYPE' => 'STRING', 'IS_ADDRESS' => 'Y', 'REQUIRED' => 'Y'],
        ];
        $sort = 100;
        $ids = [];

        foreach ($properties as $code => $fields) {
            $row = OrderPropsTable::getRow(['select' => ['ID'], 'filter' => ['=CODE' => $code, '=PERSON_TYPE_ID' => $personTypeId]]);

            if ($row === null) {
                $added = OrderPropsTable::add($fields + [
                    'PERSON_TYPE_ID' => $personTypeId, 'PROPS_GROUP_ID' => $groupId, 'CODE' => $code, 'REQUIRED' => 'N', 'DEFAULT_VALUE' => '', 'SORT' => $sort,
                    'USER_PROPS' => 'Y', 'IS_LOCATION' => 'N', 'IS_EMAIL' => 'N', 'IS_PROFILE_NAME' => 'N', 'IS_PAYER' => 'N', 'IS_LOCATION4TAX' => 'N', 'IS_FILTERED' => 'N',
                    'IS_ZIP' => 'N', 'IS_PHONE' => 'N', 'IS_ADDRESS' => 'N', 'ACTIVE' => 'Y', 'UTIL' => 'N', 'MULTIPLE' => 'N', 'ENTITY_REGISTRY_TYPE' => 'ORDER',
                    'ENTITY_TYPE' => 'ORDER', 'SETTINGS' => [],
                ]);
                self::assertTrue($added->isSuccess(), implode('; ', $added->getErrorMessages()));
                $row = ['ID' => $added->getId()];
            }

            $ids[] = (int) $row['ID'];
            $sort += 10;
        }

        self::location();
        $local = self::deliveryService('OneTrace local courier', 150.0, 1, 2);

        if (\Bitrix\Sale\Internals\ServiceRestrictionTable::getRow(['select' => ['ID'], 'filter' => ['=SERVICE_ID' => $local]]) === null) {
            \Bitrix\Sale\Internals\ServiceRestrictionTable::add([
                'SERVICE_ID' => $local, 'SERVICE_TYPE' => \Bitrix\Sale\Services\Base\RestrictionManager::SERVICE_TYPE_SHIPMENT,
                'CLASS_NAME' => '\\' . \Bitrix\Sale\Delivery\Restrictions\ByLocation::class, 'PARAMS' => [], 'SORT' => 100,
            ]);
        }

        \Bitrix\Sale\Delivery\DeliveryLocationTable::resetMultipleForOwner($local, ['L' => [self::CITY_LOCATION]]);

        return [
            'courier' => self::deliveryService('OneTrace courier', 300.0, 2, 4),
            'local' => $local,
            'bill' => self::paySystem('OneTrace bill', 'bill', 'N'),
            'cash' => self::paySystem('OneTrace cash on delivery', 'cash', 'Y'),
            'properties' => $ids,
        ];
    }

    /**
     * The checkout properties and payment systems of the test are on only during it: the other tests make orders
     * without them, as on a fresh installation.
     */
    private static function switchCheckout(bool $on): void
    {
        foreach (self::$checkout['properties'] as $id) {
            OrderPropsTable::update($id, ['ACTIVE' => $on ? 'Y' : 'N']);
        }

        foreach ([self::$checkout['bill'], self::$checkout['cash']] as $id) {
            PaySystems::update($id, ['ACTIVE' => $on ? 'Y' : 'N']);
        }
    }

    /**
     * The city of the test in Russia (the country of the locations, made when the store has none) with a ZIP code.
     */
    private static function location(): void
    {
        if (LocationTable::getRow(['select' => ['ID'], 'filter' => ['=CODE' => self::CITY_LOCATION]]) !== null) {
            return;
        }

        $types = array_column(\Bitrix\Sale\Location\TypeTable::getList(['select' => ['ID', 'CODE']])->fetchAll(), 'ID', 'CODE');
        $country = LocationTable::getRow(['select' => ['ID'], 'filter' => ['=NAME.NAME' => 'Россия', '=TYPE.CODE' => 'COUNTRY']]);
        $countryId = (int) ($country['ID'] ?? LocationTable::add([
            'CODE' => 'onetrace_test_ru', 'TYPE_ID' => (int) $types['COUNTRY'], 'PARENT_ID' => 0, 'SORT' => 100,
            'NAME' => ['ru' => ['NAME' => 'Россия'], 'en' => ['NAME' => 'Russian Federation']],
        ])->getId());
        $zip = \Bitrix\Sale\Location\ExternalServiceTable::getRow(['select' => ['ID'], 'filter' => ['=CODE' => 'ZIP']]);
        $added = LocationTable::add([
            'CODE' => self::CITY_LOCATION, 'TYPE_ID' => (int) $types['CITY'], 'PARENT_ID' => $countryId, 'SORT' => 100,
            'NAME' => ['ru' => ['NAME' => self::CITY], 'en' => ['NAME' => 'Testograd']],
            'EXTERNAL' => $zip !== null ? [['SERVICE_ID' => (int) $zip['ID'], 'XML_ID' => self::ZIP]] : [],
        ]);
        self::assertTrue($added->isSuccess(), implode('; ', $added->getErrorMessages()));
    }

    private static function deliveryService(string $name, float $price, int $from, int $to): int
    {
        $row = \Bitrix\Sale\Delivery\Services\Table::getRow(['select' => ['ID'], 'filter' => ['=NAME' => $name]]);

        if ($row !== null) {
            return (int) $row['ID'];
        }

        $result = Deliveries::add([
            'NAME' => $name, 'ACTIVE' => 'Y', 'SORT' => 100, 'PARENT_ID' => 0, 'CURRENCY' => 'RUB', 'CLASS_NAME' => '\\' . \Bitrix\Sale\Delivery\Services\Configurable::class,
            'CONFIG' => ['MAIN' => ['CURRENCY' => 'RUB', 'PRICE' => $price, 'PERIOD' => ['FROM' => $from, 'TO' => $to, 'TYPE' => 'D']]],
        ]);
        self::assertTrue($result->isSuccess(), implode('; ', $result->getErrorMessages()));

        return (int) $result->getId();
    }

    private static function paySystem(string $name, string $handler, string $cash): int
    {
        $row = PaySystemActionTable::getRow(['select' => ['ID'], 'filter' => ['=NAME' => $name]]);

        if ($row !== null) {
            return (int) $row['ID'];
        }

        $result = PaySystems::add([
            'NAME' => $name, 'PSA_NAME' => $name, 'ACTION_FILE' => $handler, 'ACTIVE' => 'Y', 'ENTITY_REGISTRY_TYPE' => 'ORDER', 'NEW_WINDOW' => 'N',
            'HAVE_PREPAY' => 'N', 'HAVE_RESULT' => 'N', 'HAVE_ACTION' => 'N', 'HAVE_PAYMENT' => 'N', 'HAVE_RESULT_RECEIVE' => 'N', 'IS_CASH' => $cash,
            'CAN_PRINT_CHECK' => 'N', 'SORT' => 500,
        ]);
        self::assertTrue($result->isSuccess(), implode('; ', $result->getErrorMessages()));

        return (int) $result->getId();
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function rpc(string $method, array $params = []): array
    {
        $response = StoreMcp::server(self::SITE)->handle('POST', ['Authorization' => 'Bearer ' . StoreMcp::key()], (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]));

        return (array) json_decode($response['body'], true);
    }

    /**
     * structuredContent of a tool call of ChatGPT; with a key — an action the customer agreed to.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function call(string $tool, array $arguments = [], ?string $token = null, ?string $key = null): array
    {
        $meta = array_filter([
            'onetrace/customer_token' => $token, 'onetrace/confirmed' => $key !== null ? true : null, 'onetrace/idempotency_key' => $key,
            'onetrace/source' => 'assistant', 'onetrace/assistant' => 'chatgpt', 'onetrace/language' => 'ru',
        ]);

        return $this->rpc('tools/call', ['name' => $tool, 'arguments' => $arguments, '_meta' => $meta])['result']['structuredContent'];
    }
}
