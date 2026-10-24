<?php

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

Loc::loadMessages(__FILE__);

/**
 * Installation of the module: tables, event handlers, agents, the recommendation component, the Web Push service
 * worker at /cdp-sw.js. Needs the modules "Online store" (sale), "Commercial catalog" (catalog) and "Information
 * blocks" (iblock) — editions "Small business" and higher.
 */
class onetrace_connector extends CModule
{
    public $MODULE_ID = 'onetrace.connector';

    public $MODULE_VERSION;

    public $MODULE_VERSION_DATE;

    public $MODULE_NAME;

    public $MODULE_DESCRIPTION;

    public $PARTNER_NAME;

    public $PARTNER_URI;

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';
        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
        $this->MODULE_NAME = Loc::getMessage('ONETRACE_MODULE_NAME');
        $this->MODULE_DESCRIPTION = Loc::getMessage('ONETRACE_MODULE_DESCRIPTION');
        $this->PARTNER_NAME = 'OneTrace';
        $this->PARTNER_URI = 'https://onetrace.pro';
    }

    public function DoInstall(): void
    {
        global $APPLICATION;

        if (PHP_VERSION_ID < 80200 || !ModuleManager::isModuleInstalled('sale') || !ModuleManager::isModuleInstalled('catalog')) {
            $APPLICATION->ThrowException(Loc::getMessage('ONETRACE_INSTALL_REQUIREMENTS'));

            return;
        }

        $this->InstallDB();
        ModuleManager::registerModule($this->MODULE_ID);
        Loader::includeModule($this->MODULE_ID);
        $this->InstallEvents();
        $this->InstallFiles();
        $this->InstallAgents();
    }

    public function DoUninstall(): void
    {
        Loader::includeModule($this->MODULE_ID);
        $this->UnInstallAgents();
        $this->UnInstallEvents();
        $this->UnInstallFiles();
        $this->UnInstallDB();
        \Bitrix\Main\Config\Option::delete($this->MODULE_ID);
        ModuleManager::unRegisterModule($this->MODULE_ID);
    }

    public function InstallDB(): bool
    {
        $errors = Application::getConnection()->executeSqlBatch((string) file_get_contents(__DIR__ . '/db/mysql/install.sql'));

        return $errors === [] || $errors === null;
    }

    public function UnInstallDB(): bool
    {
        Application::getConnection()->executeSqlBatch((string) file_get_contents(__DIR__ . '/db/mysql/uninstall.sql'));

        return true;
    }

    public function InstallEvents(): bool
    {
        \Onetrace\Connector\Events::register();

        return true;
    }

    public function UnInstallEvents(): bool
    {
        \Onetrace\Connector\Events::unregister();
        \Onetrace\Connector\StoreMcp::uninstallMailEvents();

        return true;
    }

    public function InstallFiles(): bool
    {
        CopyDirFiles(__DIR__ . '/components', Application::getDocumentRoot() . '/bitrix/components', true, true);
        // Web Push: the service worker must come from the site root; urlrewrite of Bitrix leads only to /bitrix/services/.
        CopyDirFiles(__DIR__ . '/services', Application::getDocumentRoot() . '/bitrix/services/' . $this->MODULE_ID, true, true);

        foreach (\Onetrace\Connector\Config::sites() as $siteId => $language) {
            \Bitrix\Main\UrlRewriter::add($siteId, [
                'CONDITION' => '#^/cdp-sw\.js#',
                'RULE' => '',
                'ID' => '',
                'PATH' => '/bitrix/services/' . $this->MODULE_ID . '/cdp-sw.php',
                'SORT' => 1,
            ]);
        }

        return true;
    }

    public function UnInstallFiles(): bool
    {
        DeleteDirFilesEx('/bitrix/components/onetrace/recommendations');
        DeleteDirFilesEx('/bitrix/components/onetrace/catalog.search');
        DeleteDirFilesEx('/bitrix/components/onetrace/catalog.notify');
        DeleteDirFilesEx('/bitrix/components/onetrace/product.ask');
        DeleteDirFilesEx('/bitrix/components/onetrace/loyalty.account');
        DeleteDirFilesEx('/bitrix/components/onetrace/preferences');
        DeleteDirFilesEx('/bitrix/services/' . $this->MODULE_ID);

        foreach (\Onetrace\Connector\Config::sites() as $siteId => $language) {
            \Bitrix\Main\UrlRewriter::delete($siteId, ['CONDITION' => '#^/cdp-sw\.js#']);
        }

        return true;
    }

    public function InstallAgents(): void
    {
        \CAgent::AddAgent('\Onetrace\Connector\Agent::flush();', $this->MODULE_ID, 'N', 60);
        \CAgent::AddAgent('\Onetrace\Connector\Agent::catalog();', $this->MODULE_ID, 'N', 86400);
        \CAgent::AddAgent('\Onetrace\Connector\Agent::loyalty();', $this->MODULE_ID, 'N', 60);
        \CAgent::AddAgent('\Onetrace\Connector\Agent::referrals();', $this->MODULE_ID, 'N', 60);
        \CAgent::AddAgent('\Onetrace\Connector\Agent::promo();', $this->MODULE_ID, 'N', 300);
    }

    public function UnInstallAgents(): void
    {
        \CAgent::RemoveModuleAgents($this->MODULE_ID);
    }
}
