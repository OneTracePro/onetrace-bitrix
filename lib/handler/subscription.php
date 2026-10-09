<?php

declare(strict_types=1);

namespace Onetrace\Connector\Handler;

use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Event;
use OneTrace\Commerce\Customer;
use OneTrace\Commerce\Messages;
use Onetrace\Connector\Config;
use Onetrace\Connector\Connection;
use Onetrace\Connector\Queue;

/**
 * Newsletter consents from the module "Email marketing" (sender): subscribing to a mailing (the subscription form,
 * the admin) gives the email channel consent, unsubscribing withdraws it.
 */
final class Subscription
{
    /** \Bitrix\Sender\MailingSubscription::OnAfterAdd and ::OnAfterUpdate */
    public static function onSaved(Event $event): void
    {
        $fields = (array) $event->getParameter('fields');
        $primary = $event->getParameter('primary');
        Loader::includeModule('sender');

        $row = \Bitrix\Sender\MailingSubscriptionTable::getRow([
            'select' => ['IS_UNSUB', 'EMAIL' => 'CONTACT.CODE', 'TYPE_ID' => 'CONTACT.TYPE_ID', 'SITE_ID' => 'MAILING.SITE_ID'],
            'filter' => is_array($primary) ? ['=MAILING_ID' => $primary['MAILING_ID'] ?? 0, '=CONTACT_ID' => $primary['CONTACT_ID'] ?? 0]
                : ['=MAILING_ID' => $fields['MAILING_ID'] ?? 0, '=CONTACT_ID' => $fields['CONTACT_ID'] ?? 0],
        ]);

        if ($row === null || (int) $row['TYPE_ID'] !== \Bitrix\Sender\Recipient\Type::EMAIL) {
            return;
        }

        self::consent((string) ($row['SITE_ID'] ?: (defined('SITE_ID') ? SITE_ID : '')), (string) $row['EMAIL'], $row['IS_UNSUB'] !== 'Y');
    }

    private static function consent(string $siteId, string $email, bool $subscribed): void
    {
        if ($siteId === '' || $email === '' || !Config::server($siteId) || !Config::enabled('newsletter', $siteId)) {
            return;
        }

        try {
            $topic = Config::consentTopic($siteId);
            $customer = (new Customer(null, Connection::anonymousId()))->email($email);
            Queue::event($siteId, Connection::messages($siteId)->identify($customer, [$subscribed ? Messages::subscribed('email', $topic) : Messages::unsubscribed('email', $topic)]));
        } catch (\Throwable $error) {
            Queue::log('subscription: ' . $error->getMessage());
        }
    }
}
