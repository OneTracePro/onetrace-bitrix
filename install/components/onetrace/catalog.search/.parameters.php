<?php

// The same parameters as bitrix:catalog.search: the component replaces it in the search page of the catalog.
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$parameters = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/components/bitrix/catalog.search/.parameters.php';

if (is_file($parameters)) {
    include $parameters;
}
