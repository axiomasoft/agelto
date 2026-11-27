<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

test('команда partitions:maintain успешна через pg_partman (расширение установлено миграцией)', function () {
    expect(Artisan::call('partitions:maintain'))->toBe(0);
});

test('fallback-путь создаёт недостающие партиции сырым SQL идемпотентно, когда pg_partman недоступен', function () {
    DB::statement(<<<'SQL'
        CREATE TABLE test_partition_probe (
            id bigint GENERATED ALWAYS AS IDENTITY,
            created_at timestamptz NOT NULL,
            PRIMARY KEY (id, created_at)
        ) PARTITION BY RANGE (created_at)
        SQL);

    Config::set('partitioning.tables', ['test_partition_probe' => 'created_at']);
    Config::set('partitioning.premake', 2);

    DB::statement('DROP EXTENSION IF EXISTS pg_partman CASCADE');

    expect(Artisan::call('partitions:maintain'))->toBe(0);

    $start = Carbon::now()->startOfMonth();

    for ($i = 0; $i <= 2; $i++) {
        $partition = 'test_partition_probe_'.$start->copy()->addMonthsNoOverflow($i)->format('Y_m');
        $exists = DB::selectOne('SELECT to_regclass(?) IS NOT NULL AS exists', [$partition]);

        expect($exists->exists)->toBeTrue();
    }

    // повторный запуск не должен падать на уже существующих партициях (idempotent).
    expect(Artisan::call('partitions:maintain'))->toBe(0);
});

test('команда partitions:maintain пропускает выполнение без ошибки, когда транзакционный advisory lock занят другим процессом', function () {
    $armed = true;
    DB::beforeExecuting(function (string $query) use (&$armed): void {
        if (! $armed) {
            return;
        }
        if (str_contains($query, 'partman.run_maintenance') || str_contains($query, 'pg_extension')) {
            throw new RuntimeException('Maintenance body must not execute when advisory lock is not acquired');
        }
    });

    DB::shouldReceive('transaction')->once()->andReturnUsing(fn (Closure $callback): int => $callback());
    DB::shouldReceive('getDriverName')->once()->andReturn('pgsql');
    DB::shouldReceive('selectOne')
        ->once()
        ->with('SELECT pg_try_advisory_xact_lock(hashtext(?)) AS acquired', ['agelto:partitions:maintain'])
        ->andReturn((object) ['acquired' => false]);

    expect(Artisan::call('partitions:maintain'))->toBe(0);
    $armed = false;
});
