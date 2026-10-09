<?php

// Reinstalls the module in the test Bitrix after its event handlers or files change (development only).
$_SERVER['DOCUMENT_ROOT'] = '/var/www/html';
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('NO_AGENT_CHECK', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (\Bitrix\Main\ModuleManager::isModuleInstalled('onetrace.connector')) {
    CModule::CreateModuleObject('onetrace.connector')->DoUninstall();
}

require __DIR__ . '/install-module.php';
