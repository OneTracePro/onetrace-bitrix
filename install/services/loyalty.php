<?php

/**
 * Bonus points block of the checkout (OneTrace loyalty program): GET — the state, POST — use or remove points.
 * Copied to /bitrix/services/onetrace.connector/ on install.
 */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('STOP_STATISTICS', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!\Bitrix\Main\Loader::includeModule('onetrace.connector') || !\Bitrix\Main\Loader::includeModule('sale')) {
    echo '{"loyalty":null}';

    return;
}

$response = \Onetrace\Connector\Handler\Loyalty::endpoint((string) SITE_ID);
http_response_code($response['status']);
echo json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
