<?php

declare(strict_types=1);

namespace Onetrace\Connector;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

/**
 * The tracker visitor of an order's checkout (b_onetrace_order): later events of the order (payment, refund) are
 * made in the admin, without the visitor's cookie, and still land in the same profile.
 */
final class OrderTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'b_onetrace_order';
    }

    public static function getMap(): array
    {
        return [
            new Fields\IntegerField('ORDER_ID', ['primary' => true]),
            new Fields\StringField('ANONYMOUS_ID'),
        ];
    }
}
