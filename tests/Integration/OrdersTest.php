<?php

declare(strict_types=1);

namespace Onetrace\Connector\Tests\Integration;

use Onetrace\Connector\Tests\TestCase;

final class OrdersTest extends TestCase
{
    public function testPlacedPaidCancelledAndRefundedOrdersGoFromTheServerOnceEach(): void
    {
        $product = $this->product('Linen Dress', 40.0);
        $userId = $this->user('anna-' . bin2hex(random_bytes(4)) . '@example.com');
        $this->visitor('browser-1');
        $order = $this->order($product, $userId);
        $this->visitor(null);

        $payment = $order->getPaymentCollection()->current();
        $payment->setPaid('Y');
        self::assertTrue($order->save()->isSuccess());
        $payment->setReturn('Y');
        self::assertTrue($order->save()->isSuccess());
        $order->setField('CANCELED', 'Y');
        self::assertTrue($order->save()->isSuccess());
        $this->flush();

        $completed = $this->platform->events('order_completed');
        $identify = array_values(array_filter($this->platform->messages(), static fn (array $m): bool => $m['type'] === 'identify'));

        self::assertCount(1, $completed);
        self::assertSame((string) $userId, $completed[0]['userId']);
        self::assertSame('browser-1', $completed[0]['anonymousId'], 'The checkout browser.');
        self::assertSame(80.0, $completed[0]['properties']['amount']);
        self::assertSame('RUB', $completed[0]['properties']['currency']);
        self::assertSame([['product_id' => (string) $product, 'product_name' => 'Linen Dress', 'price' => 40.0, 'quantity' => 2]], $completed[0]['properties']['products']);
        self::assertMatchesRegularExpression('/^anna(-\w+)?@example\.com$/', end($identify)['traits']['email'], 'From the order or, without the property, the account.');
        self::assertCount(1, $this->platform->events('order_paid'));
        self::assertSame('browser-1', $this->platform->events('order_paid')[0]['anonymousId'], 'Admin changes keep the checkout browser.');
        self::assertCount(1, $this->platform->events('order_refunded'));
        self::assertSame(80.0, $this->platform->events('order_refunded')[0]['properties']['amount']);
        self::assertCount(1, $this->platform->events('order_cancelled'));

        foreach (['order_completed', 'order_paid', 'order_refunded', 'order_cancelled'] as $event) {
            self::assertContract($this->platform->events($event)[0]);
        }
    }

    public function testRegistrationAndLoginIdentifyTheCustomer(): void
    {
        $this->visitor('browser-2');
        $email = 'ivan-' . bin2hex(random_bytes(4)) . '@example.com';
        $userId = $this->user($email);
        $this->flush();

        $identify = array_values(array_filter($this->platform->messages(), static fn (array $m): bool => $m['type'] === 'identify'));

        self::assertNotEmpty($identify);
        self::assertSame((string) $userId, $identify[0]['userId']);
        self::assertSame('browser-2', $identify[0]['anonymousId']);
        self::assertSame(['email' => $email, 'phone' => '+79001234567', 'first_name' => 'Anna', 'last_name' => 'Schmidt', 'language' => 'ru'], array_intersect_key($identify[0]['traits'], ['email' => 1, 'phone' => 1, 'first_name' => 1, 'last_name' => 1, 'language' => 1]));
    }

    public function testTheNewsletterCheckboxOfTheCheckoutIsAConsent(): void
    {
        $personType = self::personType();
        $exists = \Bitrix\Sale\Internals\OrderPropsTable::getRow(['select' => ['ID'], 'filter' => ['=CODE' => 'ONETRACE_SUBSCRIBE', '=PERSON_TYPE_ID' => $personType]]);

        if ($exists === null) {
            $group = \Bitrix\Sale\Internals\OrderPropsGroupTable::getRow(['select' => ['ID'], 'filter' => ['=PERSON_TYPE_ID' => $personType]]);
            $group ??= ['ID' => \Bitrix\Sale\Internals\OrderPropsGroupTable::add(['PERSON_TYPE_ID' => $personType, 'NAME' => 'Contacts', 'SORT' => 100])->getId()];
            $added = \Bitrix\Sale\Internals\OrderPropsTable::add([
                'PERSON_TYPE_ID' => $personType, 'PROPS_GROUP_ID' => (int) $group['ID'], 'NAME' => 'Subscribe to the news', 'TYPE' => 'Y/N', 'CODE' => 'ONETRACE_SUBSCRIBE',
                'REQUIRED' => 'N', 'DEFAULT_VALUE' => 'N', 'SORT' => 500, 'USER_PROPS' => 'N', 'IS_LOCATION' => 'N', 'IS_EMAIL' => 'N', 'IS_PROFILE_NAME' => 'N',
                'IS_PAYER' => 'N', 'IS_LOCATION4TAX' => 'N', 'IS_FILTERED' => 'N', 'IS_ZIP' => 'N', 'IS_PHONE' => 'N', 'IS_ADDRESS' => 'N', 'ACTIVE' => 'Y', 'UTIL' => 'N',
                'MULTIPLE' => 'N', 'ENTITY_REGISTRY_TYPE' => 'ORDER', 'ENTITY_TYPE' => 'ORDER', 'SETTINGS' => [],
            ]);
            self::assertTrue($added->isSuccess(), implode('; ', $added->getErrorMessages()));
        }

        \Onetrace\Connector\Config::set('consent_property', 'ONETRACE_SUBSCRIBE', self::SITE);
        $this->visitor('browser-6');
        $subscriber = $this->order($this->product(), $this->user(), 1, 'news@example.com', ['ONETRACE_SUBSCRIBE' => 'Y']);
        $silent = $this->order($this->product(), $this->user(), 1, 'quiet@example.com', ['ONETRACE_SUBSCRIBE' => 'N']);
        $this->flush();
        $consents = [];

        foreach ($this->platform->messages() as $message) {
            if ($message['type'] === 'identify') {
                $consents[$message['userId']] = $message['consents'] ?? [];
            }
        }

        self::assertSame([['channel' => 'email', 'status' => 'subscribed']], $consents[(string) $subscriber->getUserId()] ?? null);
        self::assertSame([], $consents[(string) $silent->getUserId()] ?? null);
    }
}
