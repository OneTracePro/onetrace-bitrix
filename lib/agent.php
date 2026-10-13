<?php

declare(strict_types=1);

namespace Onetrace\Connector;

/**
 * Agents of the module: they return their own call to stay scheduled. Run them on cron (agents_use_crontab),
 * otherwise they wait for visitors' hits.
 */
final class Agent
{
    /** Every minute: sends the queue. */
    public static function flush(): string
    {
        try {
            Queue::flush();
        } catch (\Throwable $error) {
            Queue::log('queue: ' . $error->getMessage());
        }

        return '\\' . self::class . '::flush();';
    }

    /** Every minute: confirmations of bonus points that failed at checkout. */
    public static function loyalty(): string
    {
        try {
            Loyalty::retry();
        } catch (\Throwable $error) {
            Queue::log('loyalty: ' . $error->getMessage());
        }

        return '\\' . self::class . '::loyalty();';
    }

    /** Daily: the whole catalog into the queue. */
    public static function catalog(): string
    {
        try {
            CatalogSync::queueAll();
        } catch (\Throwable $error) {
            Queue::log('catalog: ' . $error->getMessage());
        }

        return '\\' . self::class . '::catalog();';
    }
}
