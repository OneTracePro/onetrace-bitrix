<?php

declare(strict_types=1);

namespace Onetrace\Connector;

use Bitrix\Main\Loader;

/**
 * The whole catalog into the queue: every active product of the trade catalog of each site the catalog goes to.
 */
final class CatalogSync
{
    public const PAGE = 500;

    /**
     * @return int products queued
     */
    public static function queueAll(): int
    {
        Loader::includeModule('iblock');
        $count = 0;
        $seen = [];

        foreach (Config::catalogSites() as $siteId) {
            foreach (Catalog::iblocks($siteId) as $iblockId) {
                $lastId = 0;

                do {
                    $ids = [];
                    $rows = \CIBlockElement::GetList(['ID' => 'ASC'], ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y', '>ID' => $lastId], false, ['nTopCount' => self::PAGE], ['ID']);

                    while ($row = $rows->Fetch()) {
                        $ids[] = $lastId = (int) $row['ID'];
                    }

                    foreach ($ids as $id) {
                        if (!isset($seen[$id])) {
                            $seen[$id] = true;
                            Queue::product($id);
                            ++$count;
                        }
                    }
                } while (count($ids) === self::PAGE);
            }
        }

        return $count;
    }
}
