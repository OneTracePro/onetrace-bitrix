<?php

/**
 * Builds the module for installation without Composer: production files only, the onetrace-php library inside the
 * module under its own namespace (Onetrace\Connector\Vendor\OneTrace) so another module with a different version
 * of the library cannot conflict with it, and a small autoloader instead of Composer's.
 *
 *     composer install && php bin/build.php
 *
 * dist/onetrace.connector.zip — the folder for /local/modules; dist/marketplace.zip — the same files in .last_version/
 * for the Bitrix Marketplace.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$module = 'onetrace.connector';
$build = $root . '/build/' . $module;
$library = $root . '/vendor/onetracepro/onetrace-php';
$prefix = 'Onetrace\\Connector\\Vendor\\';

if (!is_dir($library . '/src')) {
    fwrite(STDERR, "Run composer install first.\n");
    exit(1);
}

$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $remove($path . '/' . $entry);
            }
        }

        rmdir($path);
    } elseif (file_exists($path)) {
        unlink($path);
    }
};

$copy = static function (string $from, string $to) use (&$copy): void {
    if (is_dir($from)) {
        @mkdir($to, 0777, true);

        foreach (scandir($from) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $copy($from . '/' . $entry, $to . '/' . $entry);
            }
        }
    } else {
        copy($from, $to);
    }
};

// Library references: "namespace OneTrace…", "use OneTrace\…", "\OneTrace\…". The module's own namespace is
// "Onetrace\Connector" (another case), so the case-sensitive pattern leaves it alone.
$scope = static function (string $code) use ($prefix): string {
    $code = (string) preg_replace('/\bnamespace OneTrace(?=[\\;])/', 'namespace ' . $prefix . 'OneTrace', $code);

    return (string) preg_replace('/(?<![\\\\\w])(\\\\?)OneTrace\\\\/', '$1' . str_replace('\\', '\\\\', $prefix) . 'OneTrace\\\\', $code);
};

$remove($root . '/build');
$remove($root . '/dist');
@mkdir($build . '/vendor/onetrace-php', 0777, true);
@mkdir($root . '/dist', 0777, true);

foreach (['include.php', 'options.php', 'install', 'lang', 'lib', 'LICENSE'] as $entry) {
    $copy($root . '/' . $entry, $build . '/' . $entry);
}

$copy($library . '/src', $build . '/vendor/onetrace-php/src');
$copy($library . '/resources', $build . '/vendor/onetrace-php/resources');
$copy($library . '/LICENSE', $build . '/vendor/onetrace-php/LICENSE');

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($build, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() === 'php') {
        file_put_contents($file->getPathname(), $scope((string) file_get_contents($file->getPathname())));
    }
}

file_put_contents($build . '/vendor/autoload.php', <<<'PHP'
<?php

// Autoloader of the module build: the bundled onetrace-php library (the module classes load by Bitrix).
spl_autoload_register(static function (string $class): void {
    $prefix = 'Onetrace\\Connector\\Vendor\\OneTrace\\';

    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = __DIR__ . '/onetrace-php/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

        if (is_file($file)) {
            require $file;
        }
    }
});

PHP);

$zip = static function (string $target, string $inside) use ($build): void {
    $archive = new ZipArchive();
    $archive->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($build, FilesystemIterator::SKIP_DOTS)) as $file) {
        $archive->addFile($file->getPathname(), $inside . '/' . substr($file->getPathname(), strlen($build) + 1));
    }

    $archive->close();
};

$zip($root . '/dist/' . $module . '.zip', $module);
$zip($root . '/dist/marketplace.zip', '.last_version');

echo "dist/{$module}.zip, dist/marketplace.zip\n";
