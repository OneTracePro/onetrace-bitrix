<?php

/**
 * Product search of the catalog from OneTrace: a drop-in replacement of bitrix:catalog.search with the same
 * parameters. The products, their order and their number come from the platform (word forms, typos, the wrong keyboard
 * layout, the visitor's interests) and are shown by bitrix:catalog.section of the store; a query with a redirect rule
 * opens its page. The setting "Product search" off, an empty query or any API error — bitrix:catalog.search as usual.
 *
 * @var array $arParams
 * @var array $arResult
 * @var CBitrixComponent $this
 * @global CMain $APPLICATION
 */
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Loader;
use Bitrix\Main\UI\PageNavigation;
use Onetrace\Connector\Search\Search;

$query = trim((string) ($_REQUEST['q'] ?? ''));
$result = null;

// Parameters as given in the page (without the escaped copies Bitrix adds).
$params = [];

foreach ($arParams as $key => $value) {
    if (!str_starts_with((string) $key, '~')) {
        $params[$key] = $arParams['~' . $key] ?? $value;
    }
}

if ($query !== '' && Loader::includeModule('onetrace.connector') && Search::enabled(SITE_ID)) {
    $navigation = new PageNavigation('search');
    $navigation->setPageSize(max(1, min(Search::MAX_PER_PAGE, (int) ($params['PAGE_ELEMENT_COUNT'] ?? 30) ?: 30)))->initFromUri();
    $result = Search::products(SITE_ID, $query, $navigation->getCurrentPage(), $navigation->getPageSize(), Search::sort((string) ($params['ELEMENT_SORT_FIELD'] ?? ''), (string) ($params['ELEMENT_SORT_ORDER'] ?? '')));
}

if ($result === null) {
    $APPLICATION->IncludeComponent('bitrix:catalog.search', $this->getTemplateName() === '.default' ? '' : $this->getTemplateName(), $params, $this->getParent() ?: null, ['HIDE_ICONS' => 'Y']);

    return;
}

if ($navigation->getCurrentPage() === 1) {
    Search::track(SITE_ID, $query, $result['total'], $result['request_id']);

    if ($result['redirect'] !== null) {
        LocalRedirect($result['redirect'], true);
    }
}

$navigation->setRecordCount($result['total']);
$arResult = ['IDS' => $result['ids'], 'TOTAL' => $result['total'], 'NAVIGATION' => $navigation, 'PARAMS' => $params];

$this->IncludeComponentTemplate();
