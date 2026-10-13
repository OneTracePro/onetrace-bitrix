<?php

/**
 * Bonus points of the signed-in customer (the personal section): the balance, points waiting for the return period,
 * the tier, points expiring soon and the latest operations; joining the program with explicit enrollment.
 *
 * @var array $arParams
 * @var array $arResult
 * @var CBitrixComponent $this
 * @var CUser $USER
 */
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

global $USER;

if (!\Bitrix\Main\Loader::includeModule('onetrace.connector') || !\Bitrix\Main\Loader::includeModule('sale') || \Onetrace\Connector\Loyalty::mode(SITE_ID) === null) {
    return;
}

if (!is_object($USER) || !$USER->IsAuthorized()) {
    $arResult = ['STATE' => 'guest'];
    $this->IncludeComponentTemplate();

    return;
}

$profile = \Onetrace\Connector\Loyalty::profile();
$client = \Onetrace\Connector\Connection::client(SITE_ID);
$request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();
$arResult = ['STATE' => 'error', 'ACCOUNT' => [], 'OPERATIONS' => [], 'JOINED' => false];

if ($request->isPost() && $request->getPost('onetrace_loyalty_join') === 'Y' && check_bitrix_sessid()) {
    try {
        $client->loyalty()->join($profile);
        $arResult['JOINED'] = true;
    } catch (\Throwable $error) {
        \Onetrace\Connector\Queue::log('loyalty join: ' . $error->getMessage());
    }
}

try {
    $account = $client->loyalty()->account($profile['type'], $profile['value']);
    $arResult['ACCOUNT'] = $account;
    $arResult['OPERATIONS'] = !empty($account['member']) ? $client->loyalty()->transactions($profile['type'], $profile['value'], ['limit' => 10])->getData() : [];
    $arResult['STATE'] = !empty($account['member']) ? 'member' : 'join';
} catch (\OneTrace\Exception\NotFoundException $error) {
    $arResult['STATE'] = 'join';
} catch (\Throwable $error) {
    \Onetrace\Connector\Queue::log('loyalty account: ' . $error->getMessage());
}

$this->IncludeComponentTemplate();
