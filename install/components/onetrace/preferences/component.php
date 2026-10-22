<?php

/**
 * Communication preferences of the signed-in customer (the personal section): subscription topics of the OneTrace
 * project by channel, how often marketing messages come, a pause and "Unsubscribe from everything".
 *
 * @var array $arParams
 * @var array $arResult
 * @var CBitrixComponent $this
 * @var CUser $USER
 * @var CMain $APPLICATION
 */
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

global $USER, $APPLICATION;

if (!\Bitrix\Main\Loader::includeModule('onetrace.connector')) {
    return;
}

if (!is_object($USER) || !$USER->IsAuthorized()) {
    $arResult = ['STATE' => 'guest'];
    $this->IncludeComponentTemplate();

    return;
}

$userId = (int) $USER->GetID();
$request = \Bitrix\Main\Application::getInstance()->getContext()->getRequest();

if ($request->isPost() && $request->getPost('onetrace_preferences') === 'Y' && check_bitrix_sessid()) {
    $action = $request->getPost('onetrace_action') === 'all' ? 'all' : 'save';
    $topics = $request->getPost('topics');
    $saved = \Onetrace\Connector\Preferences::save(
        SITE_ID,
        $userId,
        LANGUAGE_ID,
        $action,
        is_array($topics) ? $topics : [],
        (string) $request->getPost('frequency'),
        (string) $request->getPost('pause')
    );

    // Back to the page: a reload must not send the form again.
    LocalRedirect($APPLICATION->GetCurPageParam('onetrace_preferences=' . ($saved ? $action : 'error'), ['onetrace_preferences']));
}

$state = \Onetrace\Connector\Preferences::state(SITE_ID, $userId, LANGUAGE_ID);
$arResult = [
    'STATE' => $state === null ? 'error' : 'ready',
    'CHANNELS' => [],
    'TOPICS' => [],
    'FREQUENCY' => in_array($state['frequency'] ?? null, ['weekly', 'monthly'], true) ? (string) $state['frequency'] : 'default',
    'PAUSED_UNTIL' => is_string($state['paused_until'] ?? null) ? (string) $state['paused_until'] : null,
    'PAUSE_DAYS' => \Onetrace\Connector\Preferences::PAUSE_DAYS,
    'DONE' => in_array($request->getQuery('onetrace_preferences'), ['save', 'all', 'error'], true) ? (string) $request->getQuery('onetrace_preferences') : null,
];

foreach (is_array($state['channels'] ?? null) ? $state['channels'] : [] as $channel) {
    if (is_array($channel) && is_string($channel['key'] ?? null)) {
        $name = is_string($channel['name'] ?? null) ? $channel['name'] : $channel['key'];
        $arResult['CHANNELS'][] = ['KEY' => $channel['key'], 'NAME' => mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1), 'BLOCKED' => !empty($channel['blocked'])];
    }
}

foreach (is_array($state['topics'] ?? null) ? $state['topics'] : [] as $topic) {
    if (is_array($topic) && is_string($topic['key'] ?? null)) {
        $arResult['TOPICS'][] = [
            'KEY' => $topic['key'],
            'NAME' => is_string($topic['name'] ?? null) ? $topic['name'] : $topic['key'],
            'DESCRIPTION' => is_string($topic['description'] ?? null) ? trim($topic['description']) : '',
            'CHANNELS' => (array) ($topic['channels'] ?? []),
        ];
    }
}

$this->IncludeComponentTemplate();
