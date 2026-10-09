<?php

declare(strict_types=1);

namespace Onetrace\Connector\Handler;

use Bitrix\Main\Event;
use Onetrace\Connector\Catalog as CatalogItems;
use Onetrace\Connector\Queue;

/**
 * Catalog changes (modules "iblock" and "catalog"): a saved element, its prices and stock go to the queue; an
 * offer uploads its parent; a deleted product is removed from the platform catalog.
 */
final class Catalog
{
    /** @var array<int, list<int>> parents of offers being deleted */
    private static array $deleting = [];

    /** @param array<string, mixed> $fields */
    public static function onElementSaved(&$fields): void
    {
        if ((int) ($fields['ID'] ?? 0) > 0 && ($fields['RESULT'] ?? true)) {
            self::changed((int) $fields['ID']);
        }
    }

    /** OnBeforeIBlockElementDelete: the parent of an offer is known only before the deletion. */
    public static function onBeforeElementDelete($id): void
    {
        try {
            if (CatalogItems::isCatalogElement((int) $id)) {
                self::$deleting[(int) $id] = CatalogItems::catalogIds((int) $id);
            }
        } catch (\Throwable $error) {
            Queue::log('catalog ' . $id . ': ' . $error->getMessage());
        }
    }

    /** @param array<string, mixed> $fields */
    public static function onElementDeleted($fields): void
    {
        $id = (int) ($fields['ID'] ?? 0);
        $products = self::$deleting[$id] ?? [];
        unset(self::$deleting[$id]);

        foreach ($products as $productId) {
            $productId === $id ? Queue::delete($id) : Queue::product($productId);
        }
    }

    /** \Bitrix\Catalog\Model\Product::OnAfterUpdate and ::OnAfterAdd: stock and availability. */
    public static function onProductSaved(Event $event): void
    {
        self::changed((int) ($event->getParameter('id') ?? 0));
    }

    /** \Bitrix\Catalog\Model\Price::OnAfterAdd, ::OnAfterUpdate and ::OnAfterDelete. */
    public static function onPriceSaved(Event $event): void
    {
        $fields = (array) $event->getParameter('fields');
        $productId = (int) ($fields['PRODUCT_ID'] ?? 0);

        if ($productId <= 0 && (int) $event->getParameter('id') > 0) {
            $price = \Bitrix\Catalog\PriceTable::getRow(['select' => ['PRODUCT_ID'], 'filter' => ['=ID' => (int) $event->getParameter('id')]]);
            $productId = (int) ($price['PRODUCT_ID'] ?? 0);
        }

        self::changed($productId);
    }

    private static function changed(int $elementId): void
    {
        if ($elementId <= 0) {
            return;
        }

        try {
            if (!CatalogItems::isCatalogElement($elementId)) {
                return;
            }

            foreach (CatalogItems::catalogIds($elementId) as $productId) {
                Queue::product($productId);
            }
        } catch (\Throwable $error) {
            Queue::log('catalog ' . $elementId . ': ' . $error->getMessage());
        }
    }
}
