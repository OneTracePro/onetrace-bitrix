<?php

/**
 * The products of the platform in its order through bitrix:catalog.section with the parameters of the page, and the
 * page navigation over the number found by the platform.
 *
 * @var array $arResult
 * @var CBitrixComponentTemplate $this
 * @var CBitrixComponent $component
 * @global CMain $APPLICATION
 */
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$this->setFrameMode(true);

if ($arResult['IDS'] === []) {
    echo GetMessage('ONETRACE_SEARCH_NOT_FOUND');

    return;
}

$GLOBALS['onetraceSearchFilter'] = ['ID' => $arResult['IDS']];

$APPLICATION->IncludeComponent('bitrix:catalog.section', '.default', [
    'FILTER_NAME' => 'onetraceSearchFilter',
    'SECTION_ID' => '',
    'SECTION_CODE' => '',
    'INCLUDE_SUBSECTIONS' => 'Y',
    'SHOW_ALL_WO_SECTION' => 'Y',
    'PAGE_ELEMENT_COUNT' => count($arResult['IDS']),
    'ELEMENT_SORT_FIELD' => 'ID',
    'ELEMENT_SORT_ORDER' => $arResult['IDS'],
    'ELEMENT_SORT_FIELD2' => '',
    'ELEMENT_SORT_ORDER2' => '',
    'DISPLAY_TOP_PAGER' => 'N',
    'DISPLAY_BOTTOM_PAGER' => 'N',
    'SET_TITLE' => 'N',
    'SET_STATUS_404' => 'N',
    'ADD_SECTIONS_CHAIN' => 'N',
    'BROWSER_TITLE' => '',
    'META_KEYWORDS' => '',
    'META_DESCRIPTION' => '',
    'CACHE_FILTER' => 'N',
    'CACHE_GROUPS' => 'N',
] + $arResult['PARAMS'], $component, ['HIDE_ICONS' => 'Y']);

$APPLICATION->IncludeComponent('bitrix:main.pagenavigation', '', ['NAV_OBJECT' => $arResult['NAVIGATION'], 'SEF_MODE' => 'N'], $component, ['HIDE_ICONS' => 'Y']);
