<?php

declare(strict_types=1);

namespace Onetrace\Connector;

use Bitrix\Main\EventManager;
use Onetrace\Connector\Handler;

/**
 * Event handlers of the module: registered on install, removed on uninstall.
 */
final class Events
{
    /** @var list<array{0: string, 1: string, 2: class-string, 3: string}> module, event, class, method */
    public const HANDLERS = [
        ['sale', 'OnSaleOrderSaved', Handler\Sale::class, 'onOrderSaved'],
        ['sale', 'OnSaleOrderPaid', Handler\Sale::class, 'onOrderPaid'],
        ['sale', 'OnSaleOrderCanceled', Handler\Sale::class, 'onOrderCanceled'],
        ['sale', 'OnSaleShipmentEntitySaved', Handler\Sale::class, 'onShipmentSaved'],
        ['sale', 'OnSaleBasketItemSaved', Handler\Sale::class, 'onBasketItemSaved'],
        ['sale', 'OnSaleBasketItemEntityDeleted', Handler\Sale::class, 'onBasketItemDeleted'],
        ['sale', 'OnSaleComponentOrderUserResult', Handler\Sale::class, 'onCheckout'],
        ['sale', 'OnSaleComponentOrderCreated', Handler\Loyalty::class, 'onOrderCreated'],
        ['sale', 'OnSaleComponentOrderResultPrepared', Handler\Loyalty::class, 'onResultPrepared'],
        ['sale', 'OnSaleOrderSaved', Handler\Loyalty::class, 'onOrderSaved'],
        ['main', 'OnAfterUserAdd', Handler\User::class, 'onAfterAdd'],
        ['main', 'OnAfterUserUpdate', Handler\User::class, 'onAfterUpdate'],
        ['main', 'OnAfterUserLogin', Handler\User::class, 'onAfterLogin'],
        ['main', 'OnAfterUserLogout', Handler\User::class, 'onAfterLogout'],
        ['main', 'OnProlog', Handler\Page::class, 'onProlog'],
        ['iblock', 'OnAfterIBlockElementAdd', Handler\Catalog::class, 'onElementSaved'],
        ['iblock', 'OnAfterIBlockElementUpdate', Handler\Catalog::class, 'onElementSaved'],
        ['iblock', 'OnBeforeIBlockElementDelete', Handler\Catalog::class, 'onBeforeElementDelete'],
        ['iblock', 'OnAfterIBlockElementDelete', Handler\Catalog::class, 'onElementDeleted'],
        ['catalog', 'Bitrix\Catalog\Model\Product::OnAfterAdd', Handler\Catalog::class, 'onProductSaved'],
        ['catalog', 'Bitrix\Catalog\Model\Product::OnAfterUpdate', Handler\Catalog::class, 'onProductSaved'],
        ['catalog', 'Bitrix\Catalog\Model\Price::OnAfterAdd', Handler\Catalog::class, 'onPriceSaved'],
        ['catalog', 'Bitrix\Catalog\Model\Price::OnAfterUpdate', Handler\Catalog::class, 'onPriceSaved'],
        ['sender', '\Bitrix\Sender\MailingSubscription::OnAfterAdd', Handler\Subscription::class, 'onSaved'],
        ['sender', '\Bitrix\Sender\MailingSubscription::OnAfterUpdate', Handler\Subscription::class, 'onSaved'],
    ];

    public static function register(): void
    {
        $manager = EventManager::getInstance();

        foreach (self::HANDLERS as [$module, $event, $class, $method]) {
            $manager->registerEventHandler($module, $event, Config::MODULE, $class, $method);
        }
    }

    public static function unregister(): void
    {
        $manager = EventManager::getInstance();

        foreach (self::HANDLERS as [$module, $event, $class, $method]) {
            $manager->unRegisterEventHandler($module, $event, Config::MODULE, $class, $method);
        }
    }
}
