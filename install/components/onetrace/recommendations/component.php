<?php

/**
 * A recommendation widget of the platform (Site → Widgets): the tracker fills <div data-cdp-widget> with products.
 * ITEMS — ids of the current products (a product page: its id; the cart: the cart products), CATEGORY — a section id.
 *
 * @var array $arParams
 * @var array $arResult
 * @var CBitrixComponent $this
 */
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$uid = trim((string) ($arParams['WIDGET'] ?? ''));

if (preg_match('/^[a-z0-9]{1,16}$/', $uid) !== 1 || !\Bitrix\Main\Loader::includeModule('onetrace.connector') || !\Onetrace\Connector\Config::storefront(SITE_ID)) {
    return;
}

$items = is_array($arParams['ITEMS'] ?? null) ? $arParams['ITEMS'] : explode(',', (string) ($arParams['ITEMS'] ?? ''));
$arResult = [
    'WIDGET' => $uid,
    'ITEMS' => implode(',', array_filter(array_map(static fn ($id): string => trim((string) $id), $items), static fn (string $id): bool => preg_match('/^[\w.-]{1,128}$/', $id) === 1)),
    'CATEGORY' => preg_match('/^[\w.-]{1,128}$/', trim((string) ($arParams['CATEGORY'] ?? ''))) === 1 ? trim((string) $arParams['CATEGORY']) : '',
];

$this->IncludeComponentTemplate();
