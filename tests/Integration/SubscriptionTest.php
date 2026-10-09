<?php

declare(strict_types=1);

namespace Onetrace\Connector\Tests\Integration;

use Bitrix\Sender\MailingTable;
use Bitrix\Sender\Subscription;
use Onetrace\Connector\Config;
use Onetrace\Connector\Tests\TestCase;

final class SubscriptionTest extends TestCase
{
    public function testSubscribingAndUnsubscribingBecomeConsents(): void
    {
        Config::set('consent_topic', 'news', self::SITE);
        $mailing = MailingTable::getRow(['select' => ['ID'], 'filter' => ['=NAME' => 'OneTrace test']]);
        $mailingId = $mailing !== null ? (int) $mailing['ID'] : (int) MailingTable::add(['NAME' => 'OneTrace test', 'SITE_ID' => self::SITE, 'ACTIVE' => 'Y', 'IS_PUBLIC' => 'Y'])->getId();
        $email = 'reader-' . bin2hex(random_bytes(4)) . '@example.com';
        $this->visitor('browser-4');

        Subscription::add($email, [$mailingId]);
        $this->flush();
        $identify = array_values(array_filter($this->platform->messages(), static fn (array $m): bool => $m['type'] === 'identify'));

        self::assertNotEmpty($identify);
        self::assertSame($email, $identify[0]['traits']['email']);
        self::assertSame('browser-4', $identify[0]['anonymousId']);
        self::assertSame([['channel' => 'email', 'status' => 'subscribed', 'topic' => 'news']], $identify[0]['consents']);

        $this->platform->requests = [];
        $contact = \Bitrix\Sender\ContactTable::getRow(['select' => ['ID'], 'filter' => ['=CODE' => $email]]);
        \Bitrix\Sender\MailingSubscriptionTable::update(['MAILING_ID' => $mailingId, 'CONTACT_ID' => (int) $contact['ID']], ['IS_UNSUB' => 'Y']);
        $this->flush();
        $identify = array_values(array_filter($this->platform->messages(), static fn (array $m): bool => $m['type'] === 'identify'));

        self::assertSame([['channel' => 'email', 'status' => 'unsubscribed', 'topic' => 'news']], $identify[0]['consents'] ?? null);
    }
}
