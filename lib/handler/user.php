<?php

declare(strict_types=1);

namespace Onetrace\Connector\Handler;

use Onetrace\Connector\Config;
use Onetrace\Connector\Connection;
use Onetrace\Connector\Mapper;
use Onetrace\Connector\Queue;

/**
 * Customers (module "main"): identify on registration, profile changes and login — the login links the visitor's
 * browser (tracker cookie) to the account. Logout forgets the browser visitor, so the next one gets a new id.
 */
final class User
{
    /** @param array<string, mixed> $fields */
    public static function onAfterAdd(&$fields): void
    {
        if ((int) ($fields['ID'] ?? 0) > 0 && ($fields['RESULT'] ?? true)) {
            self::identify((int) $fields['ID']);

            // The friend's code of a referral link (ADR-103 of the platform): after the identify above.
            try {
                \Onetrace\Connector\Referrals::registered((int) $fields['ID'], (string) ($fields['LID'] ?? (\defined('SITE_ID') ? SITE_ID : '')));
            } catch (\Throwable $error) {
                \Onetrace\Connector\Queue::log('referral: ' . $error->getMessage());
            }
        }
    }

    /** @param array<string, mixed> $fields */
    public static function onAfterUpdate(&$fields): void
    {
        if ((int) ($fields['ID'] ?? 0) > 0 && ($fields['RESULT'] ?? true)) {
            self::identify((int) $fields['ID']);
        }
    }

    /** @param array<string, mixed> $fields */
    public static function onAfterLogin(&$fields): void
    {
        if ((int) ($fields['USER_ID'] ?? 0) > 0) {
            self::identify((int) $fields['USER_ID']);
        }
    }

    /** @param array<string, mixed> $params */
    public static function onAfterLogout($params = []): void
    {
        if (!headers_sent() && Connection::anonymousId() !== null) {
            setcookie('cdp_aid', '', ['expires' => time() - 3600, 'path' => '/']);
        }
    }

    private static function identify(int $userId): void
    {
        // In the admin and in agents the user is changed by someone else: no browser to link.
        $siteId = defined('ADMIN_SECTION') || !defined('SITE_ID') ? (string) (array_key_first(Config::sites()) ?? '') : (string) SITE_ID;

        if ($siteId === '' || !Config::server($siteId) || !Config::enabled('orders', $siteId)) {
            return;
        }

        try {
            $customer = Mapper::customer($userId, $siteId);

            if ($customer !== null) {
                Queue::event($siteId, Connection::messages($siteId)->identify($customer));
            }
        } catch (\Throwable $error) {
            Queue::log('user ' . $userId . ': ' . $error->getMessage());
        }
    }
}
