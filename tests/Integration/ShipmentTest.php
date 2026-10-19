<?php

declare(strict_types=1);

namespace Onetrace\Connector\Tests\Integration;

use Bitrix\Sale\Delivery\Services\Manager as Deliveries;
use Bitrix\Sale\Order;
use Bitrix\Sale\Shipment;
use Onetrace\Connector\Config;
use Onetrace\Connector\QueueTable;
use Onetrace\Connector\Tests\Support\TrackedDelivery;
use Onetrace\Connector\Tests\TestCase;

/**
 * Shipments handed over to delivery (order_shipped, one per shipment) and received orders (order_delivered).
 */
final class ShipmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('delivered_statuses', 'F', self::SITE);
    }

    public function testADeductedShipmentWithATrackingNumberIsShippedOnceWithTheCarrierAndTheLink(): void
    {
        $order = $this->order($this->product('Linen Dress', 40.0), $this->user(), 2, beforeSave: static fn (Order $order) => self::deliverBy($order, self::trackedDelivery()));
        $shipment = self::shipment($order);
        $shipment->setField('TRACKING_NUMBER', 'RA123456789RU');
        $shipment->setField('DEDUCTED', 'Y');
        self::save($order);
        $this->flush();

        // Saved again: the shipped status, another tracking number, a comment — the same shipment.
        $order = Order::load($order->getId());
        $shipment = self::shipment($order);
        $shipment->setField('STATUS_ID', 'DF');
        $shipment->setField('TRACKING_NUMBER', 'RA000000000RU');
        $shipment->setField('COMMENTS', 'Handed over');
        self::save($order);
        $this->flush();

        $shipped = $this->platform->events('order_shipped');

        self::assertCount(1, $shipped);
        self::assertSame((string) $order->getUserId(), $shipped[0]['userId']);
        self::assertSame((string) ($order->getField('ACCOUNT_NUMBER') ?: $order->getId()), $shipped[0]['properties']['order_id']);
        self::assertSame('Почта России', $shipped[0]['properties']['carrier']);
        self::assertSame('RA123456789RU', $shipped[0]['properties']['tracking_number']);
        self::assertSame('https://pochta.ru/tracking#RA123456789RU', $shipped[0]['properties']['tracking_url']);
        self::assertSame(2, $shipped[0]['properties']['products'][0]['quantity']);
        self::assertStringEndsWith(':' . $shipment->getId(), $shipped[0]['messageId']);
        self::assertContract($shipped[0]);
    }

    public function testASecondParcelOfTheOrderIsANewEvent(): void
    {
        $delivery = self::trackedDelivery();
        $order = $this->order($this->product('Wool Socks', 10.0), $this->user(), 3, beforeSave: static function (Order $order) use ($delivery): void {
            self::deliverBy($order, $delivery);
            // Two parcels: 2 + 1.
            $first = self::shipment($order);
            $item = $first->getShipmentItemCollection()->current();
            $item->setQuantity(2);
            $second = $order->getShipmentCollection()->createItem(Deliveries::getObjectById($delivery));
            $second->getShipmentItemCollection()->createItem($item->getBasketItem())->setQuantity(1);
        });
        [$first, $second] = self::shipments($order);

        $first->setField('TRACKING_NUMBER', 'RA111111111RU');
        $first->setField('DEDUCTED', 'Y');
        self::save($order);
        $this->flush();

        $order = Order::load($order->getId());
        $second = self::shipments($order)[1];
        $second->setField('TRACKING_NUMBER', 'RA222222222RU');
        $second->setField('DEDUCTED', 'Y');
        self::save($order);
        $this->flush();

        $shipped = $this->platform->events('order_shipped');

        self::assertCount(2, $shipped);
        self::assertSame(['RA111111111RU', 'RA222222222RU'], array_column(array_column($shipped, 'properties'), 'tracking_number'));
        self::assertSame([2, 1], [$shipped[0]['properties']['products'][0]['quantity'], $shipped[1]['properties']['products'][0]['quantity']]);
        self::assertNotSame($shipped[0]['messageId'], $shipped[1]['messageId']);
        self::assertStringEndsWith(':' . $first->getId(), $shipped[0]['messageId']);
        self::assertStringEndsWith(':' . $second->getId(), $shipped[1]['messageId']);

        foreach ($shipped as $message) {
            self::assertContract($message);
        }
    }

    public function testAShipmentWithoutATrackingNumberWaitsForIt(): void
    {
        $order = $this->order($this->product(), $this->user(), 1, beforeSave: static fn (Order $order) => self::deliverBy($order, self::trackedDelivery()));
        self::shipment($order)->setField('STATUS_ID', 'DF');
        self::save($order);
        $this->flush();

        self::assertSame([], $this->platform->events('order_shipped'), 'Waits for the tracking number.');

        $order = Order::load($order->getId());
        self::shipment($order)->setField('TRACKING_NUMBER', 'RA333333333RU');
        self::save($order);
        $this->flush();

        $order = Order::load($order->getId());
        self::shipment($order)->setField('DEDUCTED', 'Y');
        self::save($order);
        $this->flush();

        $shipped = $this->platform->events('order_shipped');

        self::assertCount(1, $shipped);
        self::assertSame('RA333333333RU', $shipped[0]['properties']['tracking_number']);
        self::assertSame('https://pochta.ru/tracking#RA333333333RU', $shipped[0]['properties']['tracking_url']);
        self::assertContract($shipped[0]);
    }

    public function testWithoutATrackingNumberTheEventGoesAfterTheWaitAndOnlyOnce(): void
    {
        $order = $this->order($this->product(), $this->user(), 1);
        self::shipment($order)->setField('DEDUCTED', 'Y');
        self::save($order);
        $this->flush();

        self::assertSame([], $this->platform->events('order_shipped'));

        // The wait is over.
        $rows = QueueTable::getList(['select' => ['ID'], 'filter' => ['=ITEM_KEY' => 'shipment' . self::shipment($order)->getId()]])->fetchAll();
        self::assertCount(1, $rows);
        QueueTable::update((int) $rows[0]['ID'], ['AVAILABLE_AT' => (new \Bitrix\Main\Type\DateTime())->add('-1 minute')]);
        $this->flush();

        $order = Order::load($order->getId());
        self::shipment($order)->setField('TRACKING_NUMBER', 'RA444444444RU');
        self::save($order);
        $this->flush();

        $shipped = $this->platform->events('order_shipped');

        self::assertCount(1, $shipped, 'A tracking number after the event gives no second one.');
        self::assertArrayNotHasKey('tracking_number', $shipped[0]['properties']);
        self::assertArrayNotHasKey('carrier', $shipped[0]['properties'], '"Без доставки" is not a carrier.');
        self::assertContract($shipped[0]);
    }

    public function testDeliveredStatusesGiveOrderDeliveredOnce(): void
    {
        if (!\Bitrix\Sale\Internals\StatusTable::getRowById('OD')) {
            \Bitrix\Sale\Internals\StatusTable::add(['ID' => 'OD', 'TYPE' => 'O', 'SORT' => 150, 'NOTIFY' => 'N']);
            \Bitrix\Sale\Internals\StatusLangTable::add(['STATUS_ID' => 'OD', 'LID' => 'ru', 'NAME' => 'Доставлен']);
        }

        $order = $this->order($this->product(), $this->user(), 1);
        $order->setField('STATUS_ID', 'F');
        self::save($order);
        $order->setField('COMMENTS', 'Thank you');
        self::save($order);
        $this->flush();

        $delivered = $this->platform->events('order_delivered');

        self::assertCount(1, $delivered);
        self::assertSame((string) ($order->getField('ACCOUNT_NUMBER') ?: $order->getId()), $delivered[0]['properties']['order_id']);
        self::assertContract($delivered[0]);

        // Own statuses: "Доставлен" and then "Выполнен" — one event.
        Config::set('delivered_statuses', 'OD,F', self::SITE);
        $this->platform->requests = [];
        $order = $this->order($this->product(), $this->user(), 1);
        $order->setField('STATUS_ID', 'OD');
        self::save($order);
        $order->setField('STATUS_ID', 'F');
        self::save($order);
        $this->flush();

        self::assertCount(1, $this->platform->events('order_delivered'));

        // None chosen — not sent.
        Config::set('delivered_statuses', '', self::SITE);
        $this->platform->requests = [];
        $order = $this->order($this->product(), $this->user(), 1);
        $order->setField('STATUS_ID', 'F');
        self::save($order);
        $this->flush();

        self::assertSame([], $this->platform->events('order_delivered'));
    }

    /**
     * A delivery service with tracking links ("Почта России"), created once.
     */
    private static function trackedDelivery(): int
    {
        $row = \Bitrix\Sale\Delivery\Services\Table::getRow(['select' => ['ID'], 'filter' => ['=CODE' => 'onetrace_tracked']]);

        if ($row !== null) {
            return (int) $row['ID'];
        }

        $result = Deliveries::add([
            'CODE' => 'onetrace_tracked', 'NAME' => 'Почта России', 'ACTIVE' => 'Y', 'SORT' => 900, 'PARENT_ID' => 0, 'CURRENCY' => 'RUB',
            'CLASS_NAME' => '\\' . TrackedDelivery::class,
            'CONFIG' => ['MAIN' => ['CURRENCY' => 'RUB', 'PRICE' => 0, 'PERIOD' => ['FROM' => 1, 'TO' => 3, 'TYPE' => 'D']]],
        ]);
        self::assertTrue($result->isSuccess(), implode('; ', $result->getErrorMessages()));

        return (int) $result->getId();
    }

    private static function deliverBy(Order $order, int $deliveryId): void
    {
        $service = Deliveries::getObjectById($deliveryId);
        self::shipment($order)->setFields(['DELIVERY_ID' => $deliveryId, 'DELIVERY_NAME' => $service->getNameWithParent()]);
    }

    private static function shipment(Order $order): Shipment
    {
        return self::shipments($order)[0];
    }

    /**
     * @return list<Shipment>
     */
    private static function shipments(Order $order): array
    {
        $shipments = [];

        foreach ($order->getShipmentCollection() as $shipment) {
            if (!$shipment->isSystem()) {
                $shipments[] = $shipment;
            }
        }

        return $shipments;
    }

    private static function save(Order $order): void
    {
        $result = $order->save();
        self::assertTrue($result->isSuccess(), implode('; ', $result->getErrorMessages()));
    }
}
