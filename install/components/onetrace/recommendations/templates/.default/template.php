<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

/** @var array $arResult */
?>
<div data-cdp-widget="<?= htmlspecialcharsbx($arResult['WIDGET']) ?>"<?php if ($arResult['ITEMS'] !== '') { ?> data-item="<?= htmlspecialcharsbx($arResult['ITEMS']) ?>"<?php } ?><?php if ($arResult['CATEGORY'] !== '') { ?> data-category="<?= htmlspecialcharsbx($arResult['CATEGORY']) ?>"<?php } ?>></div>
