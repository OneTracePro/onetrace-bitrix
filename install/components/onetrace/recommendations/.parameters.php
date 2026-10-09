<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$arComponentParameters = [
    'PARAMETERS' => [
        'WIDGET' => ['PARENT' => 'BASE', 'NAME' => GetMessage('ONETRACE_RECOMMENDATIONS_WIDGET'), 'TYPE' => 'STRING', 'DEFAULT' => ''],
        'ITEMS' => ['PARENT' => 'BASE', 'NAME' => GetMessage('ONETRACE_RECOMMENDATIONS_ITEMS'), 'TYPE' => 'STRING', 'DEFAULT' => ''],
        'CATEGORY' => ['PARENT' => 'BASE', 'NAME' => GetMessage('ONETRACE_RECOMMENDATIONS_CATEGORY'), 'TYPE' => 'STRING', 'DEFAULT' => ''],
        'CACHE_TIME' => ['DEFAULT' => 3600],
    ],
];
