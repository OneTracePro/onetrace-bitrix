<?php

declare(strict_types=1);

namespace Onetrace\Connector\Tests\Support;

use Bitrix\Sale\Delivery\Services\Configurable;

/**
 * A delivery service with the tracking of the Russian Post, as delivery modules have one (the tracking link).
 */
final class TrackedDelivery extends Configurable
{
    protected $trackingClass = '\Bitrix\Sale\Delivery\Tracking\RusPost';
}
