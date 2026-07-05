<?php

declare(strict_types=1);

use Tests\Support\TestEnvironmentGuard;

test('страж изоляции пропускает БД с суффиксом _test', function () {
    config(['database.connections.pgsql.database' => 'agelto_test']);

    TestEnvironmentGuard::assertIsolatedTestDatabase($this->app);
})->throwsNoExceptions();

test('страж изоляции аварийно прерывает прогон на не-тестовой БД', function () {
    config(['database.connections.pgsql.database' => 'agelto']);

    TestEnvironmentGuard::assertIsolatedTestDatabase($this->app);
})->throws(RuntimeException::class, 'ИЗОЛЯЦИЯ ТЕСТОВ НАРУШЕНА');
