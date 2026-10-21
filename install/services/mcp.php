<?php

/**
 * MCP server of the store for the OneTrace AI chat bot (store MCP contract v1): POST with the platform key
 * (Authorization: Bearer). The site comes in ?site=, as the server address of each site is registered with it.
 * Copied to /bitrix/services/onetrace.connector/ on install.
 */

$site = (string) ($_GET['site'] ?? '');

if (preg_match('/^[a-z0-9_]{2}$/i', $site) === 1) {
    define('SITE_ID', $site);
}

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('NOT_CHECK_PERMISSIONS', true);
define('STOP_STATISTICS', true);
define('BX_SECURITY_SESSION_VIRTUAL', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

$APPLICATION->RestartBuffer();

if (!defined('SITE_ID') || SITE_ID !== $site || !\Bitrix\Main\Loader::includeModule('onetrace.connector') || !\Bitrix\Main\Loader::includeModule('sale')
    || !\Onetrace\Connector\StoreMcp::enabled($site)) {
    http_response_code(404);
    die();
}

header('Cache-Control: no-store');
\Onetrace\Connector\StoreMcp::server($site)->serve();
die();
