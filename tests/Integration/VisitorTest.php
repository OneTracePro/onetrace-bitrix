<?php

declare(strict_types=1);

namespace Onetrace\Connector\Tests\Integration;

use Onetrace\Connector\Config;
use Onetrace\Connector\Connection;
use Onetrace\Connector\StoreMcp;
use Onetrace\Connector\Tests\TestCase;
use Onetrace\Connector\Visitor;

/**
 * The signed user id of the tracker (ADR-158 of the platform): the service visitor.php gives the signed-in customer
 * with userHash = HMAC-SHA256(identity key, user id), the page carries only the script that asks for it.
 */
final class VisitorTest extends TestCase
{
    public function testGivesTheSignedIdOfASignedInCustomerOnly(): void
    {
        global $USER;
        $userId = $this->user();

        self::assertFalse(Visitor::enabled(self::SITE), 'Without the identity key.');
        self::assertSame(['userId' => null, 'userHash' => null], Visitor::current(self::SITE));

        Config::set('identity_key', 'cdp_ik_test', self::SITE);
        Connection::reset(['transport' => $this->platform]);
        self::assertTrue(Visitor::enabled(self::SITE));
        self::assertSame(['userId' => null, 'userHash' => null], Visitor::current(self::SITE), 'A guest.');

        self::assertTrue($USER->Authorize($userId));

        try {
            // The same HMAC as IdentityVerification::hash() of the platform.
            self::assertSame(['userId' => (string) $userId, 'userHash' => hash_hmac('sha256', (string) $userId, 'cdp_ik_test')], Visitor::current(self::SITE));
        } finally {
            $USER->Logout();
        }
    }

    public function testThePageScriptAsksTheServiceWithTheLoginCookieAndHasNoKey(): void
    {
        Config::set('identity_key', 'cdp_ik_test', self::SITE);
        $script = Visitor::trackerScript(self::SITE);

        self::assertStringContainsString('"/bitrix/services/onetrace.connector/visitor.php?site=s1"', $script);
        self::assertStringContainsString('"' . StoreMcp::COOKIE . '"', $script);
        self::assertStringContainsString("w.cdp.identify(j.userId,{},j.userHash?{userHash:j.userHash}:{})", $script);
        self::assertStringContainsString("l('cdp_uhash')!==j.userHash", $script);
        self::assertStringNotContainsString('cdp_ik_test', $script);

        // The smoke check of the storefront runs after the suite: the tracker stays on.
        Config::set('part_tracker', 'N', self::SITE);

        try {
            self::assertFalse(Visitor::enabled(self::SITE));
        } finally {
            Config::set('part_tracker', 'Y', self::SITE);
        }
    }
}
