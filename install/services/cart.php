<?php

/**
 * "Add to cart" of the OneTrace search widget: POST quantity with the X-Requested-With header, the product of the
 * catalog in ?id=, the site in ?site=. Copied to /bitrix/services/onetrace.connector/ on install.
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

if (!\Bitrix\Main\Loader::includeModule('onetrace.connector') || !\Bitrix\Main\Loader::includeModule('sale')) {
    http_response_code(503);
    echo '{"ok":false}';
    die();
}

$response = \Onetrace\Connector\Search\Cart::endpoint((string) SITE_ID);

if ($response['status'] === 405) {
    header('Allow: POST');
}

http_response_code($response['status']);
echo json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
die();
