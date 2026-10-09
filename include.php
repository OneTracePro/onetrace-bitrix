<?php

// The module classes (lib/) load by the Bitrix autoloader; the bundled onetrace-php library — by its own.
if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
