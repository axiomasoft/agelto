<?php

declare(strict_types=1);

/**
 * PHPUnit подключает этот bootstrap ПОСЛЕ применения <php><env> из phpunit.xml,
 * но ДО vendor/autoload.php. Здесь дублируется изоляция окружения, чтобы Laravel
 * никогда не увидел dev-БД из $_SERVER, даже если порядок инструментов (CI,
 * IDE-раннер, локальный .env) изменится или сломается phpunit.xml.
 *
 * Ключевой инвариант: к моменту загрузки фреймворка имя БД заканчивается на `_test`.
 *
 * @see https://github.com/laravel/framework/blob/master/src/Illuminate/Foundation/Testing/RefreshDatabase.php
 */
$isolate = static function (): void {
    $pairs = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => 'postgres',
        'DB_PORT' => '5432',
        // Имя обязано заканчиваться на `_test` — это и проверяет TestEnvironmentGuard.
        'DB_DATABASE' => 'agelto_test',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
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
