<?php

// A category of the search suggestions (/bitrix/services/onetrace.connector/category.php?id=…): the tracker knows
// only the section id, the store knows its page.
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('STOP_STATISTICS', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

$id = (int) ($_GET['id'] ?? 0);
$section = $id > 0 && \Bitrix\Main\Loader::includeModule('iblock')
    ? CIBlockSection::GetList([], ['ID' => $id, 'ACTIVE' => 'Y', 'GLOBAL_ACTIVE' => 'Y'], false, ['ID', 'IBLOCK_ID', 'SECTION_PAGE_URL'])->GetNext()
    : false;

LocalRedirect(is_array($section) && (string) $section['SECTION_PAGE_URL'] !== '' ? (string) $section['SECTION_PAGE_URL'] : SITE_DIR);
