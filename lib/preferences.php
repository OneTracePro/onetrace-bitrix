<?php

declare(strict_types=1);

namespace Onetrace\Connector;

use OneTrace\Exception\NotFoundException;
use OneTrace\Identity;

/**
 * Communication preferences of a customer in the OneTrace preference center: subscription topics by channel, how
 * often marketing messages come, a pause and "Unsubscribe from everything" (the onetrace:preferences component).
 */
final class Preferences
{
    public const PAUSE_DAYS = [30, 90];

    /**
     * Preferences in the language of the site; a customer the platform does not know yet has nothing to choose;
     * null — the platform did not answer.
     *
     * @return array<string, mixed>|null
     */
    public static function state(string $siteId, int $userId, string $language): ?array
    {
        $profile = Identity::userId((string) $userId);

        try {
            return Connection::client($siteId)->profiles()->preferences($profile['type'], $profile['value'], $language);
        } catch (NotFoundException $error) {
            return ['channels' => [], 'topics' => []];
        } catch (\Throwable $error) {
            Queue::log('preferences: ' . $error->getMessage());

            return null;
        }
    }

    /**
     * Sends the choice: every topic of the form in every channel that can change (an unticked box — unsubscribed),
     * the frequency and the pause; "all" unsubscribes from every marketing message.
     *
     * @param 'save'|'all' $action
     * @param array<mixed> $topics topic → channel → "Y" for ticked boxes
     */
    public static function save(string $siteId, int $userId, string $language, string $action, array $topics, string $frequency, string $pause): bool
    {
        $state = self::state($siteId, $userId, $language);

        if ($state === null) {
            return false;
        }

        if ($action === 'all') {
            $changes = ['unsubscribe_all' => true];
        } else {
            $blocked = [];

            foreach ((array) ($state['channels'] ?? []) as $channel) {
                if (is_array($channel) && !empty($channel['blocked'])) {
                    $blocked[] = (string) $channel['key'];
                }
            }

            $choices = [];

            foreach ((array) ($state['topics'] ?? []) as $topic) {
                if (!is_array($topic)) {
                    continue;
                }

                foreach ((array) ($topic['channels'] ?? []) as $channel => $subscribed) {
                    if ($subscribed !== null && !in_array((string) $channel, $blocked, true)) {
                        $choices[(string) $topic['key']][(string) $channel] = !empty($topics[$topic['key']][$channel]);
                    }
                }
            }

            $changes = ['topics' => $choices, 'frequency' => in_array($frequency, ['weekly', 'monthly'], true) ? $frequency : null];

            if ($pause === 'none') {
                $changes['paused_until'] = null;
            } elseif (in_array((int) $pause, self::PAUSE_DAYS, true)) {
                $changes['paused_until'] = new \DateTimeImmutable('+' . (int) $pause . ' days');
            }
        }

        $changes['language'] = $language;
        $profile = Identity::userId((string) $userId);

        try {
            Connection::client($siteId)->profiles()->updatePreferences($profile['type'], $profile['value'], $changes);
        } catch (\Throwable $error) {
            Queue::log('preferences save: ' . $error->getMessage());

            return false;
        }

        return true;
    }
}
