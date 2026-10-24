<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

/**
 * @var array $arResult
 * @var CBitrixComponentTemplate $this
 */
$this->setFrameMode(true);
?>
<div data-cdp-ask data-product-id="<?= htmlspecialcharsbx($arResult['PRODUCT_ID']) ?>"></div>
