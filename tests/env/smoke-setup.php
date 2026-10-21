<?php

// Storefront check (tests/env/smoke.sh): settings of the site s1, a product, a page with the recommendation widget,
// a product out of stock with the "Notify me when available" component, the store MCP server. Prints the ids of the
// product, the section and the product out of stock, and the key of the store MCP server. With "queue" — prints the waiting messages instead.
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

// "platform <url>": the address of the platform (the fake one of the redirect check).
if (($argv[1] ?? '') === 'platform') {
    Config::set('url', (string) $argv[2], 's1');

    return;
}

if (($argv[1] ?? '') === 'queue') {
    foreach (QueueTable::getList(['select' => ['KIND', 'PAYLOAD'], 'order' => ['ID' => 'ASC']])->fetchAll() as $row) {
        echo $row['KIND'], ' ', $row['PAYLOAD'], "\n";
    }

    return;
}

foreach (['url' => 'https://cdp.example.com', 'write_key' => 'cdp_wk_smoke', 'secret_key' => 'cdp_sk_smoke', 'push' => 'Y', 'part_search' => 'Y', 'store_mcp' => 'Y'] as $name => $value) {
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

$section = (int) (new CIBlockSection())->Add(['IBLOCK_ID' => $iblock['ID'], 'NAME' => 'Smoke section', 'CODE' => 'smoke-section-' . bin2hex(random_bytes(3)), 'ACTIVE' => 'Y']);
(new CIBlock())->Update((int) $iblock['ID'], ['SECTION_PAGE_URL' => '#SITE_DIR#/catalog/#SECTION_CODE#/']);

file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/onetrace-search.php', '<?php require $_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php"; '
    . '$APPLICATION->IncludeComponent("onetrace:catalog.search", "", ["IBLOCK_TYPE" => "onetrace_test", "IBLOCK_ID" => ' . (int) $iblock['ID'] . ', "PAGE_ELEMENT_COUNT" => 12, "PRICE_CODE" => ["BASE"]]); '
    . 'require $_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php";');

// A product out of stock and its page with the place of the "Notify me when available" form.
$soldOut = (int) (new CIBlockElement())->Add(['IBLOCK_ID' => $iblock['ID'], 'NAME' => 'Smoke sold out', 'ACTIVE' => 'Y', 'CODE' => 'smoke-' . bin2hex(random_bytes(3))]);
\Bitrix\Catalog\Model\Product::add(['ID' => $soldOut, 'QUANTITY' => 0, 'QUANTITY_TRACE' => 'Y', 'CAN_BUY_ZERO' => 'N']);
\Onetrace\Connector\Queue::clear();
file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/onetrace-notify.php', '<?php require $_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php"; '
    . '$APPLICATION->IncludeComponent("onetrace:catalog.notify", "", ["PRODUCT_ID" => ' . $soldOut . ']); '
    . 'require $_SERVER["DOCUMENT_ROOT"] . "/bitrix/footer.php";');

echo $id, ' ', $section, ' ', $soldOut, ' ', \Onetrace\Connector\StoreMcp::key();
