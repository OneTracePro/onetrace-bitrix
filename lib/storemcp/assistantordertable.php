<?php

declare(strict_types=1);

namespace Onetrace\Connector\StoreMcp;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

/**
 * Orders placed through the store MCP (b_onetrace_assistant_order): the idempotency key of store_create_order (a
 * repeat gives the same order, also after the cache is cleared), where the order came from and the AI assistant —
 * for ai_assistant in the order events. ORDER_ID 0 — the order is being placed.
 */
final class AssistantOrderTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'b_onetrace_assistant_order';
    }

    public static function getMap(): array
    {
        return [
            new Fields\StringField('IDEMPOTENCY_KEY', ['primary' => true]),
            new Fields\IntegerField('ORDER_ID'),
            new Fields\StringField('SITE_ID'),
            new Fields\StringField('SOURCE'),
            new Fields\StringField('ASSISTANT'),
            new Fields\DatetimeField('CREATED_AT'),
        ];
    }
}
