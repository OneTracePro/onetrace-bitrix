<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$arComponentParameters = [
    'PARAMETERS' => [
        'PRODUCT_ID' => ['PARENT' => 'BASE', 'NAME' => GetMessage('ONETRACE_PRODUCT_ASK_PRODUCT_ID'), 'TYPE' => 'STRING', 'DEFAULT' => ''],
    ],
];
