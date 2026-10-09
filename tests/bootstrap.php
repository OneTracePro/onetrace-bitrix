<?php

declare(strict_types=1);

/**
 * Integration tests run inside the Bitrix container of tests/env/run.sh: the real Bitrix "Small business" with the
 * module installed from the working copy; requests to the platform go to a recording transport.
 */
$_SERVER['DOCUMENT_ROOT'] = '/var/www/html';
$_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('NO_AGENT_CHECK', true);
define('BX_WITH_ON_AFTER_EPILOG', false);
define('SITE_ID', 's1');

require dirname(__DIR__) . '/vendor/autoload.php';
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

foreach (['iblock', 'catalog', 'sale', 'currency', 'sender', 'onetrace.connector'] as $module) {
    if (!\Bitrix\Main\Loader::includeModule($module)) {
        fwrite(STDERR, "Module {$module} is not installed.\n");
        exit(1);
    }
}
