<?php

// Storefront check (tests/env/smoke.sh): settings of the site s1, a product, a page with the recommendation widget.
// Prints the product id. With "queue" — prints the waiting messages instead.
$_SERVER['DOCUMENT_ROOT'] = '/var/www/html';
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
define('NO_AGENT_CHECK', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Onetrace\Connector\Config;
use Onetrace\Connector\QueueTable;

foreach (['iblock', 'catalog', 'onetrace.connector'] as $module) {
    Loader::includeModule($module);
}

if (($argv[1] ?? '') === 'queue') {
    foreach (QueueTable::getList(['select' => ['KIND', 'PAYLOAD'], 'order' => ['ID' => 'ASC']])->fetchAll() as $row) {
        echo $row['KIND'], ' ', $row['PAYLOAD'], "\n";
    }

    return;
}

foreach (['url' => 'https://cdp.example.com', 'write_key' => 'cdp_wk_smoke', 'secret_key' => 'cdp_sk_smoke', 'push' => 'Y'] as $name => $value) {
    Config::set($name, $value, 's1');
}

\Onetrace\Connector\Queue::clear();
$iblock = CIBlock::GetList([], ['CODE' => 'onetrace_test', 'CHECK_PERMISSIONS' => 'N'])->Fetch();
$id = (int) (new CIBlockElement())->Add(['IBLOCK_ID' => $iblock['ID'], 'NAME' => 'Smoke', 'ACTIVE' => 'Y', 'CODE' => 'smoke-' . bin2hex(random_bytes(3))]);
\Bitrix\Catalog\Model\Product::add(['ID' => $id, 'QUANTITY' => 5]);
\Bitrix\Catalog\Model\Price::add(['PRODUCT_ID' => $id, 'CATALOG_GROUP_ID' => 1, 'PRICE' => 10, 'CURRENCY' => 'RUB']);
\Onetrace\Connector\Queue::clear();

file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/onetrace-widget.php', <<<'PHP'
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/header.php';
$APPLICATION->IncludeComponent('onetrace:recommendations', '', ['WIDGET' => 'k3x9', 'ITEMS' => '17,18', 'CATEGORY' => '5']);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/footer.php';
PHP);

echo $id;
