<?php

declare(strict_types=1);

namespace Onetrace\Connector\Tests\Integration;

use Onetrace\Connector\Config;
use Onetrace\Connector\Queue;
use Onetrace\Connector\QueueTable;
use Onetrace\Connector\Tests\TestCase;

final class QueueTest extends TestCase
{
    public function testRetriesWhenThePlatformIsDownAndSendsLater(): void
    {
        Queue::event(self::SITE, ['type' => 'track', 'event' => 'test', 'userId' => '1', 'properties' => []]);
        $this->platform->push(503, ['message' => 'down']);

        self::assertSame(['sent' => 0, 'retried' => 1, 'dropped' => 0], $this->flush());
        self::assertSame(1, Queue::stats()['retrying']);
        self::assertStringContainsString('down', (string) Queue::lastError());

        QueueTable::update((int) QueueTable::getRow(['select' => ['ID']])['ID'], ['AVAILABLE_AT' => (new \Bitrix\Main\Type\DateTime())->add('-1 minute')]);

        self::assertSame(['sent' => 1, 'retried' => 0, 'dropped' => 0], $this->flush());
        self::assertSame(0, Queue::stats()['waiting']);
    }

    public function testPausesTheSiteOnARejectedKeyUntilTheSettingsAreSaved(): void
    {
        Queue::event(self::SITE, ['type' => 'track', 'event' => 'test', 'userId' => '1', 'properties' => []]);
        $this->platform->push(401, ['message' => 'Invalid key']);
        $this->flush();

        self::assertTrue(Queue::paused(self::SITE));
        self::assertSame(['sent' => 0, 'retried' => 0, 'dropped' => 0], $this->flush(), 'Nothing is sent while paused.');
        self::assertSame(1, Queue::stats()['waiting']);

        Queue::resume(self::SITE);

        self::assertSame(1, $this->flush()['sent']);
    }

    public function testSendsNothingWithoutTheSecretKey(): void
    {
        Config::set('secret_key', '', self::SITE);
        Queue::event(self::SITE, ['type' => 'track', 'event' => 'test', 'userId' => '1', 'properties' => []]);

        self::assertSame(0, Queue::stats()['waiting']);
    }
}
