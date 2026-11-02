<?php

/**
 * The signed-in customer for the tracker: the user id and its signature with the identity key (ADR-158 of the
 * platform). The tracker script asks for it when the login cookie of the module is set, so the id never goes into a
 * cached page. Copied to /bitrix/services/onetrace.connector/ on install.
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

$visitor = defined('SITE_ID') && SITE_ID === $site && \Bitrix\Main\Loader::includeModule('onetrace.connector')
    ? \Onetrace\Connector\Visitor::current($site)
    : ['userId' => null, 'userHash' => null];

if ($visitor['userId'] === null) {
    \Onetrace\Connector\StoreMcp::forgetLogin();
}

echo json_encode($visitor);
die();
