<?php

/**
 * The chat token of the signed-in customer for the AI chat bot (store MCP): the tracker script asks for it when the
 * login cookie of the module is set, so the token never goes into a cached page. Copied to
 * /bitrix/services/onetrace.connector/ on install.
 */

$site = (string) ($_GET['site'] ?? '');

if (preg_match('/^[a-z0-9_]{2}$/i', $site) === 1) {
    define('SITE_ID', $site);
}

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('STOP_STATISTICS', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

$APPLICATION->RestartBuffer();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, private');

$token = defined('SITE_ID') && SITE_ID === $site && \Bitrix\Main\Loader::includeModule('onetrace.connector') ? \Onetrace\Connector\StoreMcp::customerToken($site) : null;

if ($token === null) {
    \Onetrace\Connector\StoreMcp::forgetLogin();
}

echo json_encode(['token' => $token]);
die();
