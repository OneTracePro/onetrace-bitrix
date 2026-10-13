<?php

declare(strict_types=1);

namespace Onetrace\Connector;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

/**
 * Bonus points of an order (b_onetrace_loyalty): the redemption confirmed with the order number, or waiting for the
 * agent to confirm it while the hold on the platform is alive.
 */
final class LoyaltyTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'b_onetrace_loyalty';
    }

    public static function getMap(): array
    {
        return [
            new Fields\IntegerField('ORDER_ID', ['primary' => true]),
            new Fields\StringField('SITE_ID'),
            new Fields\StringField('REDEMPTION'),
            new Fields\IntegerField('POINTS'),
            new Fields\FloatField('DISCOUNT'),
            new Fields\DatetimeField('CONFIRMED_AT', ['nullable' => true]),
            new Fields\DatetimeField('CREATED_AT'),
        ];
    }
}
