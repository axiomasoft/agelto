<?php

declare(strict_types=1);
use Tests\Support\TestFilesystemIsolation;

require_once __DIR__.'/Support/TestFilesystemIsolation.php';

// ParaTest sets TEST_TOKEN before this bootstrap. PHP caches its temp directory
// during framework bootstrap, so its process root must be selected before autoload.
$parallelToken = $_SERVER['TEST_TOKEN'] ?? $_ENV['TEST_TOKEN'] ?? (getenv('TEST_TOKEN') !== false ? getenv('TEST_TOKEN') : 'serial');
$runtimeProcess = getenv('PROCESS_CORE_PROCESS');
$parallelToken = is_string($runtimeProcess) && $runtimeProcess !== '' ? $runtimeProcess : $parallelToken;

if (! is_string($parallelToken) || preg_match('/^[A-Za-z0-9_-]+$/', $parallelToken) !== 1) {
    throw new RuntimeException('ИЗОЛЯЦИЯ ТЕСТОВ НАРУШЕНА: TEST_TOKEN содержит недопустимые символы.');
}

$testingRoot = dirname(__DIR__).'/storage/framework/testing/'.$parallelToken;
TestFilesystemIsolation::assertNoSymlinks(path: $testingRoot);

foreach (['disks', 'logs', 'tmp', 'auth', 'artifacts', 'views'] as $directory) {
    $path = $testingRoot.'/'.$directory;
    TestFilesystemIsolation::assertNoSymlinks(path: $path);

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
    $rig = getenv('PROCESS_CORE_TEST') === '1';
    $queue = $rig ? (getenv('PROCESS_CORE_QUEUE_MODE') ?: 'sync') : 'sync';

    if (! in_array($queue, ['sync', 'redis'], strict: true)) {
        throw new RuntimeException('ИЗОЛЯЦИЯ ТЕСТОВ НАРУШЕНА: неизвестный режим очереди process-core.');
    }

    $pairs = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => $rig ? 'core-postgres' : 'postgres',
        'DB_PORT' => '5432',
        // Bootstrap всегда начинает с canonical DB; worker token Laravel добавляет после boot.
        'DB_DATABASE' => 'agelto_test',
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => $queue,
        'FILESYSTEM_DISK' => 'local',
        'VIEW_COMPILED_PATH' => $testingRoot.'/views',
        'TMPDIR' => $testingRoot.'/tmp',
        'TMP' => $testingRoot.'/tmp',
        'TEMP' => $testingRoot.'/tmp',
    ];

    if ($rig) {
        $pairs = [...$pairs,
            'DB_USERNAME' => 'core_test',
            'DB_PASSWORD' => 'core-test-only',
            'REDIS_HOST' => 'core-redis',
            'REDIS_PASSWORD' => 'core-test-only',
            'REDIS_URL' => '',
            'REDIS_PREFIX' => 'agelto_core_test:',
            'HORIZON_PREFIX' => 'agelto_core_test:horizon:',
        ];
    }

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

if ($parallelToken === 'serial' || str_starts_with($parallelToken, 'runtime-')) {
    register_shutdown_function(static function () use ($parallelToken): void {
        TestFilesystemIsolation::cleanup(process: $parallelToken);
    });
}
