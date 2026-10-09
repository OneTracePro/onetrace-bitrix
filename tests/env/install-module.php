<?php

// Installs the module from the working copy into the test Bitrix (tests/env/run.sh), once.
$_SERVER['DOCUMENT_ROOT'] = '/var/www/html';
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('NO_AGENT_CHECK', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

// A clean install lets only administrators see and buy at the base price: open it to everyone, as shops do.
\Bitrix\Main\Loader::includeModule('catalog');
CCatalogGroup::Update(1, ['USER_GROUP' => [1, 2], 'USER_GROUP_BUY' => [1, 2]]);

if (\Bitrix\Main\ModuleManager::isModuleInstalled('onetrace.connector')) {
    echo "The module is installed.\n";

    return;
}

$module = CModule::CreateModuleObject('onetrace.connector');
$module->DoInstall();

if ($exception = $APPLICATION->GetException()) {
    fwrite(STDERR, $exception->GetString() . "\n");
    exit(1);
}

echo "The module is installed.\n";
