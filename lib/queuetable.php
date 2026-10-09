<?php

declare(strict_types=1);

namespace Onetrace\Connector;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

/**
 * Outgoing queue of the module (b_onetrace_queue).
 */
final class QueueTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'b_onetrace_queue';
    }

    public static function getMap(): array
    {
        return [
            new Fields\IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
            new Fields\StringField('SITE_ID'),
            new Fields\StringField('KIND'),
            new Fields\StringField('ITEM_KEY'),
            new Fields\TextField('PAYLOAD'),
            new Fields\IntegerField('ATTEMPTS', ['default_value' => 0]),
            new Fields\DatetimeField('AVAILABLE_AT'),
            new Fields\TextField('LAST_ERROR', ['nullable' => true]),
            new Fields\DatetimeField('CREATED_AT'),
        ];
    }
}
