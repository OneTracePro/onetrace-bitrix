<?php

// Web Push service worker at /cdp-sw.js (an urlrewrite rule of the module): loads the platform's worker.
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('STOP_STATISTICS', true);
define('BX_SECURITY_SESSION_VIRTUAL', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (!\Bitrix\Main\Loader::includeModule('onetrace.connector') || !\Onetrace\Connector\Config::push(SITE_ID) || \Onetrace\Connector\Config::url(SITE_ID) === '') {
    \CHTTP::SetStatus('404 Not Found');
    die();
}

$APPLICATION->RestartBuffer();
header('Content-Type: application/javascript; charset=utf-8');
header('Service-Worker-Allowed: /');
header('Cache-Control: public, max-age=3600');
echo 'importScripts(' . json_encode(\Onetrace\Connector\Config::url(SITE_ID) . '/tracker/cdp-sw.js', JSON_UNESCAPED_SLASHES) . ');';
die();
