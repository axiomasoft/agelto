<?php

declare(strict_types=1);
use Tests\Support\TestFilesystemIsolation;

// ParaTest sets TEST_TOKEN before this bootstrap. PHP caches its temp directory
// during framework bootstrap, so its process root must be selected before autoload.
$parallelToken = $_SERVER['TEST_TOKEN'] ?? $_ENV['TEST_TOKEN'] ?? 'serial';

if (! is_string($parallelToken) || preg_match('/^[A-Za-z0-9_-]+$/', $parallelToken) !== 1) {
    throw new RuntimeException('ИЗОЛЯЦИЯ ТЕСТОВ НАРУШЕНА: TEST_TOKEN содержит недопустимые символы.');
}

$testingRoot = dirname(__DIR__).'/storage/framework/testing/'.$parallelToken;

foreach (['disks', 'logs', 'tmp', 'auth', 'artifacts'] as $directory) {
    $path = $testingRoot.'/'.$directory;

    if (! is_dir($path) && ! mkdir(directory: $path, permissions: 0755, recursive: true) && ! is_dir($path)) {
        throw new RuntimeException("Не удалось создать изолированный testing-root: {$path}");
    }
}

/**
 * PHPUnit подключает этот bootstrap ПОСЛЕ применения <php><env> из phpunit.xml,
 * но ДО vendor/autoload.php. Здесь дублируется изоляция окружения, чтобы Laravel
 * никогда не увидел dev-БД из $_SERVER, даже если порядок инструментов (CI,
 * IDE-раннер, локальный .env) изменится или сломается phpunit.xml.
 *
 * Ключевой инвариант: к моменту загрузки фреймворка имя БД равно `agelto_test`.
 *
 * @see https://github.com/laravel/framework/blob/master/src/Illuminate/Foundation/Testing/RefreshDatabase.php
 */
$isolate = static function () use ($testingRoot): void {
    $pairs = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => 'postgres',
        'DB_PORT' => '5432',
        // Bootstrap всегда начинает с canonical DB; worker token Laravel добавляет после boot.
        'DB_DATABASE' => 'agelto_test',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'FILESYSTEM_DISK' => 'local',
        'TMPDIR' => $testingRoot.'/tmp',
        'TMP' => $testingRoot.'/tmp',
        'TEMP' => $testingRoot.'/tmp',
    ];

    foreach ($pairs as $key => $value) {
        // Перезаписываем все три источника, которые читает Laravel env():
        // $_SERVER имеет приоритет над $_ENV, putenv() закрывает getenv().
        $_SERVER[$key] = $value;
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }
};

$isolate();

require dirname(__DIR__).'/vendor/autoload.php';

if ($parallelToken === 'serial') {
    register_shutdown_function(static function (): void {
        TestFilesystemIsolation::cleanup(process: 'serial');
    });
}
