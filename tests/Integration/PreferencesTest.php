<?php

declare(strict_types=1);

namespace Onetrace\Connector\Tests\Integration;

use OneTrace\Http\Request;
use OneTrace\Http\Response;
use Onetrace\Connector\Preferences;
use Onetrace\Connector\Tests\TestCase;

/**
 * The onetrace:preferences component: topics by channel from OneTrace, the choice sent back with unticked boxes as
 * unsubscribed, the pause, "Unsubscribe from everything" and a customer the platform does not know yet.
 */
final class PreferencesTest extends TestCase
{
    private int $status = 200;

    private string $url = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform->responder = function (Request $request): ?Response {
            if (preg_match('#/profiles/user_id/\d+/preferences$#', (string) parse_url($request->getUrl(), PHP_URL_PATH)) !== 1) {
                return null;
            }

            $this->url = $request->getUrl();

            return $this->status === 200 ? $this->json(200, [
                'profile_id' => 7,
                'language' => 'en',
                'channels' => [['key' => 'email', 'name' => 'email', 'blocked' => false], ['key' => 'sms', 'name' => 'SMS', 'blocked' => true]],
                'topics' => [
                    ['key' => 'news', 'name' => 'Store news', 'description' => 'Once a month', 'channels' => ['email' => true, 'sms' => false]],
                    ['key' => 'offers', 'name' => 'Special offers', 'description' => '', 'channels' => ['email' => false, 'sms' => null]],
                ],
                'frequency' => 'weekly',
                'paused_until' => '2026-12-01T00:00:00+00:00',
                'preferences_url' => 'https://crm.example.com/preferences/x',
                'preferences_url_expires_at' => '2026-11-21T00:00:00+00:00',
            ]) : $this->json($this->status, ['message' => 'Profile not found']);
        };
    }

    public function testTheComponentShowsTopicsByChannelTheFrequencyAndThePause(): void
    {
        global $APPLICATION, $USER;

        $userId = $this->user();
        $USER->Authorize($userId);

        try {
            ob_start();
            $APPLICATION->IncludeComponent('onetrace:preferences', '', []);
            $html = (string) ob_get_clean();
        } finally {
            $USER->Logout();
        }

        self::assertStringContainsString('Store news', $html);
        self::assertMatchesRegularExpression('#<th style="text-align:center">Email</th>#', $html);
        self::assertMatchesRegularExpression('/name="topics\[news\]\[email\]"[^>]*checked/', $html);
        self::assertMatchesRegularExpression('/name="topics\[news\]\[sms\]"[^>]*disabled/', $html);
        self::assertStringNotContainsString('topics[offers][sms]', $html);
        self::assertMatchesRegularExpression('/value="weekly"\s+selected/', $html);
        self::assertStringContainsString('value="keep"', $html);
        self::assertStringContainsString('language=' . LANGUAGE_ID, $this->url);
    }

    public function testSavingSendsUntickedBoxesAsUnsubscribedAndSkipsBlockedChannels(): void
    {
        self::assertTrue(Preferences::save(self::SITE, 42, 'en', 'save', ['offers' => ['email' => 'Y']], 'monthly', '30'));
        $body = $this->lastPut();
        self::assertSame(['news' => ['email' => false], 'offers' => ['email' => true]], $body['topics']);
        self::assertSame('monthly', $body['frequency']);
        self::assertSame('en', $body['language']);
        self::assertEqualsWithDelta(time() + 30 * 86400, strtotime((string) $body['paused_until']), 60);

        self::assertTrue(Preferences::save(self::SITE, 42, 'en', 'save', [], 'default', 'keep'));
        self::assertNull($this->lastPut()['frequency']);
        self::assertArrayNotHasKey('paused_until', $this->lastPut());

        self::assertTrue(Preferences::save(self::SITE, 42, 'en', 'all', [], 'default', 'keep'));
        self::assertTrue($this->lastPut()['unsubscribe_all']);
    }

    public function testACustomerThePlatformDoesNotKnowYetHasNothingToChoose(): void
    {
        $this->status = 404;
        self::assertSame(['channels' => [], 'topics' => []], Preferences::state(self::SITE, 42, 'en'));

        $this->status = 403;
        self::assertNull(Preferences::state(self::SITE, 42, 'en'));
        self::assertFalse(Preferences::save(self::SITE, 42, 'en', 'all', [], 'default', 'keep'));
    }

    /**
     * @return array<string, mixed>
     */
    private function lastPut(): array
    {
        $puts = array_values(array_filter($this->platform->requests, static fn (array $request): bool => $request['method'] === 'PUT'));

        return (array) end($puts)['body'];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(int $status, array $body): Response
    {
        return new Response($status, ['content-type' => 'application/json'], (string) json_encode($body));
    }
}
