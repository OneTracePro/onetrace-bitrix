<?php

declare(strict_types=1);

namespace Onetrace\Connector;

use OneTrace\Exception\NotFoundException;
use OneTrace\Exception\ValidationException;
use OneTrace\Identity;

/**
 * Referral program of OneTrace: a new user with the cdp_ref cookie of the tracker (a ?ref= link) — the friend's code
 * waits for the identify of the user and goes to the platform from an agent; while the platform does not know the
 * profile (404) it is repeated up to 5 times. Registration templates differ from site to site, so there is no field.
 */
final class Referrals
{
    private const COOKIE = 'cdp_ref';

    private const OPTION = 'referrals_pending';

    private const ATTEMPTS = 5;

    /** OnAfterUserAdd: the code of the cookie for a new user of a site with bonus points. */
    public static function registered(int $userId, string $siteId): void
    {
        $code = self::normalize((string) ($_COOKIE[self::COOKIE] ?? ''));

        if ($code === null || $siteId === '' || !Config::server($siteId) || Loyalty::mode($siteId) === null) {
            return;
        }

        $pending = self::pending();
        $pending[] = ['site' => $siteId, 'user' => $userId, 'code' => $code, 'attempt' => 1, 'at' => time() + 30];
        Config::set(self::OPTION, (string) json_encode($pending));
    }

    /**
     * Sends the codes that are due.
     *
     * @return int sent
     */
    public static function process(): int
    {
        $left = [];
        $sent = 0;

        foreach (self::pending() as $item) {
            if ($item['at'] > time()) {
                $left[] = $item;

                continue;
            }

            try {
                Connection::client($item['site'])->loyalty()->refer(Identity::userId((string) $item['user']), $item['code'], 'bx-referral-' . $item['user'] . '-' . $item['attempt']);
                $sent++;
            } catch (ValidationException $error) {
                // Own code, an existing customer, referrals off — nothing to repeat.
                Queue::log(sprintf('referral %d: %s', $item['user'], $error->getMessage()));
            } catch (\Throwable $error) {
                if (!$error instanceof NotFoundException) {
                    Queue::log(sprintf('referral %d: %s', $item['user'], $error->getMessage()));
                }

                if ($item['attempt'] < self::ATTEMPTS) {
                    $left[] = ['attempt' => $item['attempt'] + 1, 'at' => time() + 60 * $item['attempt']] + $item;
                }
            }
        }

        Config::set(self::OPTION, (string) json_encode($left));

        return $sent;
    }

    /**
     * @return list<array{site: string, user: int, code: string, attempt: int, at: int}>
     */
    public static function pending(): array
    {
        $data = json_decode(Config::get(self::OPTION), true);
        $items = [];

        foreach (\is_array($data) ? $data : [] as $item) {
            if (\is_array($item) && isset($item['site'], $item['user'], $item['code'], $item['attempt'], $item['at'])) {
                $items[] = ['site' => (string) $item['site'], 'user' => (int) $item['user'], 'code' => (string) $item['code'], 'attempt' => (int) $item['attempt'], 'at' => (int) $item['at']];
            }
        }

        return $items;
    }

    private static function normalize(string $code): ?string
    {
        $code = strtoupper(trim($code));

        return preg_match('/^[A-Z0-9]{4,16}$/', $code) === 1 ? $code : null;
    }
}
