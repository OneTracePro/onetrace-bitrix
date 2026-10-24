<?php

/**
 * "Ask AI about this product" of the platform on the product page: <div data-cdp-ask data-product-id="{product id}">
 * — the tracker draws the widget of the project there (Site → Product questions: the AI answers from the description,
 * the attributes and the knowledge base of the shop, or a button of the chat with the product), when it is enabled in
 * OneTrace; otherwise the element stays empty. PRODUCT_ID — the element of the page: an offer gives its product, a copy
 * of another language site the product of the uploaded catalog (the ids of the platform catalog). Nothing with the
 * setting "Show “Ask AI about this product”" off, without the tracker or for an element outside the catalog. With the
 * component on the page the module does not add the place under the buy buttons itself.
 *
 * @var array $arParams
 * @var array $arResult
 * @var CBitrixComponent $this
 */
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$siteId = defined('SITE_ID') ? (string) SITE_ID : '';

if (!\Bitrix\Main\Loader::includeModule('onetrace.connector') || !\Onetrace\Connector\Config::ask($siteId)) {
    return;
}

try {
    $productId = \Onetrace\Connector\Catalog::platformId((int) ($arParams['PRODUCT_ID'] ?? 0));
} catch (\Throwable $error) {
    \Onetrace\Connector\Queue::log('ask: ' . $error->getMessage());

    return;
}

if ($productId === null) {
    return;
}

$arResult = ['PRODUCT_ID' => (string) $productId];

$this->IncludeComponentTemplate();
