<?php

/**
 * "Notify me when available" of the platform on the product page: <div data-cdp-notify="{product id}"> for a product
 * that cannot be bought now — the tracker draws the form of the project there (if the project has an active one).
 * PRODUCT_ID — the element of the page (an offer gives its product: the id of the catalog and of the events). Nothing
 * for an available product, with the setting "Show the “Notify when available” form" off or without the tracker.
 *
 * @var array $arParams
 * @var array $arResult
 * @var CBitrixComponent $this
 */
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$siteId = defined('SITE_ID') ? (string) SITE_ID : '';

if (!\Bitrix\Main\Loader::includeModule('onetrace.connector') || !\Onetrace\Connector\Config::notify($siteId)) {
    return;
}

try {
    $availability = \Onetrace\Connector\Catalog::availability((int) ($arParams['PRODUCT_ID'] ?? 0));
} catch (\Throwable $error) {
    \Onetrace\Connector\Queue::log('notify: ' . $error->getMessage());

    return;
}

if ($availability === null || $availability[1]) {
    return;
}

$arResult = ['PRODUCT_ID' => (string) $availability[0]];

$this->IncludeComponentTemplate();
