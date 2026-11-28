<?php

declare(strict_types=1);

use Tests\Support\TestEnvironmentGuard;

test('bootstrap страж пропускает только exact agelto_test', function () {
    config(['database.connections.pgsql.database' => 'agelto_test']);

    TestEnvironmentGuard::assertBootstrapTestDatabase($this->app);
})->throwsNoExceptions();

test('страж изоляции аварийно прерывает прогон на не-тестовой БД', function () {
    config(['database.connections.pgsql.database' => 'agelto']);

    TestEnvironmentGuard::assertBootstrapTestDatabase($this->app);
})->throws(RuntimeException::class, 'ИЗОЛЯЦИЯ ТЕСТОВ НАРУШЕНА');

test('parallel страж принимает только exact Laravel DB активного token', function () {
    config(['database.connections.pgsql.database' => 'agelto_test_test_2']);

    TestEnvironmentGuard::assertParallelTestDatabase(
        app: $this->app,
        database: 'agelto_test_test_2',
        token: '2',
    );
})->throwsNoExceptions();

test('parallel страж не принимает DB другого token', function () {
    config(['database.connections.pgsql.database' => 'agelto_test_test_1']);

    TestEnvironmentGuard::assertParallelTestDatabase(
        app: $this->app,
        database: 'agelto_test_test_1',
        token: '2',
    );
})->throws(RuntimeException::class, 'Laravel parallel worker выбрал неверную БД');
