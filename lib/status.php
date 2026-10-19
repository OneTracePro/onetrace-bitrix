<?php

declare(strict_types=1);

namespace Onetrace\Connector;

use Bitrix\Main\Localization\Loc;
use OneTrace\Client;

Loc::loadMessages(__FILE__);

/**
 * The connection check of a site (the button in the settings): both keys, their project and the catalog permission.
 */
final class Status
{
    /**
     * @return list<array{ok: bool, text: string}>
     */
    public static function check(string $siteId): array
    {
        if (Config::url($siteId) === '') {
            return [['ok' => false, 'text' => (string) Loc::getMessage('ONETRACE_STATUS_NO_URL')]];
        }

        $client = new Client(Config::url($siteId), ['write_key' => Config::writeKey($siteId) ?: null, 'secret_key' => Config::secretKey($siteId) ?: null, 'timeout' => 10.0, 'max_retries' => 0]);
        $lines = [];

        foreach (['write' => Config::writeKey($siteId), 'secret' => Config::secretKey($siteId)] as $type => $value) {
            $label = (string) Loc::getMessage('ONETRACE_STATUS_KEY_' . strtoupper($type));

            if ($value === '') {
                $lines[] = ['ok' => false, 'text' => $label . ': ' . Loc::getMessage('ONETRACE_STATUS_NOT_SET')];

                continue;
            }

            try {
                $key = $client->checkKey($type);
                $project = (string) ($key['project']['name'] ?? '');
                $lines[] = ['ok' => $key['type'] === $type, 'text' => $label . ': ' . Loc::getMessage('ONETRACE_STATUS_PROJECT', ['#PROJECT#' => $project])];

                if ($type === 'secret' && !in_array('products.write', (array) ($key['scopes'] ?? []), true)) {
                    $lines[] = ['ok' => false, 'text' => (string) Loc::getMessage('ONETRACE_STATUS_NO_CATALOG')];
                }

                if ($type === 'secret' && Config::get('loyalty', $siteId, 'N') !== 'N' && array_diff(['loyalty.read', 'loyalty.write'], (array) ($key['scopes'] ?? [])) !== []) {
                    $lines[] = ['ok' => false, 'text' => (string) Loc::getMessage('ONETRACE_STATUS_NO_LOYALTY')];
                }

                if ($type === 'secret' && !in_array('messages.send', (array) ($key['scopes'] ?? []), true)) {
                    $lines[] = ['ok' => false, 'text' => (string) Loc::getMessage('ONETRACE_STATUS_NO_EMAILS')];
                }

                if ($type === 'secret' && PromoSync::pools($siteId) !== [] && !in_array('promo.read', (array) ($key['scopes'] ?? []), true)) {
                    $lines[] = ['ok' => false, 'text' => (string) Loc::getMessage('ONETRACE_STATUS_NO_PROMO')];
                }
            } catch (\Throwable $error) {
                $lines[] = ['ok' => false, 'text' => $label . ': ' . $error->getMessage()];
            }
        }

        if (Queue::paused($siteId)) {
            $lines[] = ['ok' => false, 'text' => Loc::getMessage('ONETRACE_STATUS_PAUSED', ['#REASON#' => (string) Queue::pausedReason($siteId)])];
        }

        return $lines;
    }
}
