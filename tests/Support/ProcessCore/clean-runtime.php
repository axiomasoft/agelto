<?php

declare(strict_types=1);
use Tests\Support\TestFilesystemIsolation;

require_once dirname(__DIR__).'/TestFilesystemIsolation.php';

if (getenv('PROCESS_CORE_TEST') !== '1' || getenv('APP_ENV') !== 'testing'
    || dirname(__DIR__, 3) !== '/var/www/html' || ! is_file('/.dockerenv')
    || getenv('DB_HOST') !== 'core-postgres' || getenv('DB_DATABASE') !== 'agelto_test') {
    throw new RuntimeException('Process-core runtime cleanup requires the isolated testing project.');
}

$cache = dirname(__DIR__, 3).'/bootstrap/cache';

if (is_link($cache) || realpath($cache) !== $cache) {
    throw new RuntimeException('Process-core bootstrap cache escapes its owned mount.');
}

foreach (glob($cache.'/*.php') ?: [] as $path) {
    if (! unlink($path)) {
        throw new RuntimeException('Не удалось удалить test bootstrap cache.');
    }
}

$base = dirname(__DIR__, 3).'/storage/framework/testing';

foreach (glob($base.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
    // The HTTP server is still running; its own process-root is removed on exit.
    if (str_starts_with(basename($directory), 'runtime-')) {
        continue;
    }

    TestFilesystemIsolation::cleanup(process: basename($directory));
}

$storage = dirname(__DIR__, 3).'/storage';
$remove = static function (string $path) use (&$remove): void {
    TestFilesystemIsolation::assertNoSymlinks(path: $path);

    foreach (glob($path.'/{*,.[!.]*,..?*}', GLOB_BRACE) ?: [] as $entry) {
        if (is_link($entry) || is_file($entry)) {
            if (! unlink($entry)) {
                throw new RuntimeException('Не удалось удалить test runtime file.');
            }
        } elseif (is_dir($entry)) {
            $remove($entry);
            rmdir($entry);
        }
    }
};

foreach (['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views'] as $path) {
    $remove($storage.'/'.$path);
}
