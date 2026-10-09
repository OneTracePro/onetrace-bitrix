<?php

declare(strict_types=1);

namespace Onetrace\Connector;

use Bitrix\Main\Application;
use Bitrix\Main\Type\DateTime;
use OneTrace\Commerce\Retry;
use OneTrace\Exception\OneTraceException;

/**
 * Outgoing queue: messages and catalog changes wait in b_onetrace_queue and are sent in batches by the agent every
 * minute, so the store never waits for the platform and nothing is lost while it is unavailable.
 *
 * Kinds: "event" — a message for POST /batch; "product" — an element id to upload (read fresh when sent);
 * "delete" — a product id to remove from the catalog.
 */
final class Queue
{
    public const EVENT = 'event';

    public const PRODUCT = 'product';

    public const DELETE = 'delete';

    /** Messages per request to the platform. */
    public const BATCH = 100;

    /** Products per request: building items reads elements and prices, so the batch is smaller. */
    public const PRODUCT_BATCH = 50;

    /**
     * @param array<string, mixed>|null $message
     */
    public static function event(string $siteId, ?array $message): void
    {
        if ($message !== null && Config::server($siteId)) {
            self::push($siteId, self::EVENT, '', (string) json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        }
    }

    /**
     * A product to upload to every project the catalog goes to; one waiting item per product and site.
     */
    public static function product(int $productId): void
    {
        foreach (Config::catalogSites() as $siteId) {
            self::push($siteId, self::PRODUCT, 'p' . $productId, (string) $productId, true);
        }
    }

    public static function delete(int $productId): void
    {
        foreach (Config::catalogSites() as $siteId) {
            self::push($siteId, self::DELETE, 'p' . $productId, (string) $productId, true);
        }
    }

    /**
     * Sends what is due.
     *
     * @return array{sent: int, retried: int, dropped: int}
     */
    public static function flush(int $limit = 5000): array
    {
        $result = ['sent' => 0, 'retried' => 0, 'dropped' => 0];
        $sites = array_column(QueueTable::getList(['select' => ['SITE_ID'], 'group' => ['SITE_ID']])->fetchAll(), 'SITE_ID');

        foreach (array_map('strval', $sites) as $siteId) {
            foreach ([self::EVENT, self::PRODUCT, self::DELETE] as $kind) {
                while ($limit > 0 && !self::paused($siteId) && Config::server($siteId)
                    && ($rows = self::due($siteId, $kind, min($limit, $kind === self::PRODUCT ? self::PRODUCT_BATCH : self::BATCH))) !== []) {
                    $limit -= count($rows);

                    foreach (self::send($siteId, $kind, $rows) as $key => $count) {
                        $result[$key] += $count;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @return array{waiting: int, retrying: int}
     */
    public static function stats(): array
    {
        return [
            'waiting' => QueueTable::getCount(),
            'retrying' => QueueTable::getCount(['>ATTEMPTS' => 0]),
        ];
    }

    public static function lastError(): ?string
    {
        $row = QueueTable::getRow(['select' => ['LAST_ERROR'], 'filter' => ['!=LAST_ERROR' => null], 'order' => ['ID' => 'DESC']]);

        return is_string($row['LAST_ERROR'] ?? null) && $row['LAST_ERROR'] !== '' ? $row['LAST_ERROR'] : null;
    }

    /** Sending of a site stops until its settings are saved again: the platform rejected the key. */
    public static function paused(string $siteId): bool
    {
        return self::pausedReason($siteId) !== null;
    }

    public static function pausedReason(string $siteId): ?string
    {
        $reason = Config::get('paused', $siteId);

        return $reason !== '' ? $reason : null;
    }

    public static function resume(?string $siteId = null): void
    {
        foreach ($siteId !== null ? [$siteId] : array_keys(Config::sites()) as $site) {
            Config::set('paused', '', $site);
        }
    }

    /** Removes everything waiting (tests and the module removal). */
    public static function clear(): void
    {
        Application::getConnection()->truncateTable(QueueTable::getTableName());
    }

    private static function push(string $siteId, string $kind, string $key, string $payload, bool $unique = false): void
    {
        if ($unique) {
            // A newer change of the same product replaces the waiting one (upload ⇄ delete).
            $rows = QueueTable::getList(['select' => ['ID'], 'filter' => ['=SITE_ID' => $siteId, '=ITEM_KEY' => $key, '=ATTEMPTS' => 0]]);

            while ($row = $rows->fetch()) {
                QueueTable::delete((int) $row['ID']);
            }
        }

        QueueTable::add([
            'SITE_ID' => $siteId, 'KIND' => $kind, 'ITEM_KEY' => $key, 'PAYLOAD' => $payload, 'ATTEMPTS' => 0,
            'AVAILABLE_AT' => new DateTime(), 'CREATED_AT' => new DateTime(),
        ]);
    }

    /**
     * @return list<array{ID: int, PAYLOAD: string, ATTEMPTS: int}>
     */
    private static function due(string $siteId, string $kind, int $limit): array
    {
        $rows = [];
        $result = QueueTable::getList([
            'select' => ['ID', 'PAYLOAD', 'ATTEMPTS'],
            'filter' => ['=SITE_ID' => $siteId, '=KIND' => $kind, '<=AVAILABLE_AT' => new DateTime()],
            'order' => ['ID' => 'ASC'],
            'limit' => $limit,
        ]);

        while ($row = $result->fetch()) {
            $rows[] = ['ID' => (int) $row['ID'], 'PAYLOAD' => (string) $row['PAYLOAD'], 'ATTEMPTS' => (int) $row['ATTEMPTS']];
        }

        return $rows;
    }

    /**
     * @param list<array{ID: int, PAYLOAD: string, ATTEMPTS: int}> $rows
     *
     * @return array{sent: int, retried: int, dropped: int}
     */
    private static function send(string $siteId, string $kind, array $rows): array
    {
        try {
            $client = Connection::client($siteId);

            if ($kind === self::EVENT) {
                $client->events()->batch(array_map(static fn (array $row): array => (array) json_decode($row['PAYLOAD'], true), $rows));
            } elseif ($kind === self::PRODUCT) {
                [$items, $categories, $missing] = Catalog::items($siteId, array_map('intval', array_column($rows, 'PAYLOAD')));

                if ($items !== []) {
                    $client->products()->upsert($items, $categories);
                }

                if ($missing !== []) {
                    $client->products()->delete($missing);
                }
            } else {
                $client->products()->delete(array_values(array_map('strval', array_column($rows, 'PAYLOAD'))));
            }
        } catch (\Throwable $error) {
            return self::failed($siteId, $kind, $rows, $error);
        }

        self::remove(array_column($rows, 'ID'));

        return ['sent' => count($rows), 'retried' => 0, 'dropped' => 0];
    }

    /**
     * @param list<array{ID: int, PAYLOAD: string, ATTEMPTS: int}> $rows
     *
     * @return array{sent: int, retried: int, dropped: int}
     */
    private static function failed(string $siteId, string $kind, array $rows, \Throwable $error): array
    {
        // Errors outside the platform's answers (database, a bug while building items) are retried, not dropped.
        $decision = $error instanceof OneTraceException ? Retry::decide($error) : Retry::RETRY;
        $message = mb_substr(get_class($error) . ': ' . $error->getMessage(), 0, 1000);

        if ($decision === Retry::SETTINGS) {
            Config::set('paused', $message, $siteId);
            self::log('sending paused for site ' . $siteId . ': ' . $message);

            return ['sent' => 0, 'retried' => 0, 'dropped' => 0];
        }

        // Invalid data in a batch: send the messages one by one to drop only the broken ones.
        if ($decision === Retry::DROP && count($rows) > 1) {
            $result = ['sent' => 0, 'retried' => 0, 'dropped' => 0];

            foreach ($rows as $row) {
                foreach (self::send($siteId, $kind, [$row]) as $key => $count) {
                    $result[$key] += $count;
                }
            }

            return $result;
        }

        if ($decision === Retry::DROP) {
            self::remove(array_column($rows, 'ID'));
            self::log(sprintf('dropped %s %s: %s', $kind, mb_substr($rows[0]['PAYLOAD'], 0, 200), $message));

            return ['sent' => 0, 'retried' => 0, 'dropped' => count($rows)];
        }

        $retried = 0;
        $dropped = [];

        foreach ($rows as $row) {
            $attempt = $row['ATTEMPTS'] + 1;
            $delay = Retry::delay($attempt);

            if ($delay === null) {
                $dropped[] = $row['ID'];

                continue;
            }

            QueueTable::update($row['ID'], ['ATTEMPTS' => $attempt, 'AVAILABLE_AT' => (new DateTime())->add('+' . $delay . ' seconds'), 'LAST_ERROR' => $message]);
            ++$retried;
        }

        if ($dropped !== []) {
            self::remove($dropped);
            self::log(sprintf('dropped %d %s item(s) after all retries: %s', count($dropped), $kind, $message));
        }

        return ['sent' => 0, 'retried' => $retried, 'dropped' => count($dropped)];
    }

    /**
     * @param array<int> $ids
     */
    private static function remove(array $ids): void
    {
        foreach ($ids as $id) {
            QueueTable::delete((int) $id);
        }
    }

    public static function log(string $message): void
    {
        \CEventLog::Add(['SEVERITY' => 'WARNING', 'AUDIT_TYPE_ID' => 'ONETRACE', 'MODULE_ID' => Config::MODULE, 'ITEM_ID' => '', 'DESCRIPTION' => $message]);
    }
}
