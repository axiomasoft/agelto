<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

test('retention-конфиг покрывает 2 эфемерные таблицы и исключает financial provenance и domain events', function () {
    $retentionTables = array_keys(config('partitioning.retention'));

    expect($retentionTables)->toEqualCanonicalizing([
        'prc_fetches',
        'prc_operation_logs',
    ]);

    expect($retentionTables)->not->toContain('prc_events', 'prc_revisions');
});

test('«вечные» таблицы (eternal) не пересекаются с DROP-retention списком', function () {
    $eternal = config('partitioning.eternal');
    $retentionTables = array_keys(config('partitioning.retention'));

    expect(array_intersect($eternal, $retentionTables))->toBeEmpty();
    expect($eternal)->toContain('prc_events', 'prc_revisions');
});

test('миграция выставляет partman.part_config.retention для эфемерных таблиц и не трогает prc_revisions', function () {
    $rows = DB::select(<<<'SQL'
        SELECT parent_table, retention, retention_keep_table
        FROM partman.part_config
        ORDER BY parent_table
        SQL);

    $byTable = collect($rows)->keyBy(fn (object $row): string => $row->parent_table);

    foreach (config('partitioning.retention') as $table => $rule) {
        $row = $byTable->get('public.'.$table);

        expect($row)->not->toBeNull();
        expect($row->retention)->toBe($rule['months'].' months');
        expect((bool) $row->retention_keep_table)->toBe($rule['keep_table']);
    }

    $revisions = $byTable->get('public.prc_revisions');

    expect($revisions)->not->toBeNull();
    expect($revisions->retention)->toBeNull();

    $events = $byTable->get('public.prc_events');

    expect($events)->not->toBeNull();
    expect($events->retention)->toBeNull();
});

test('миграция part_config.retention идемпотентна — повторный up() не бросает и не меняет значения', function () {
    $migration = include base_path('database/migrations/2026_07_09_100050_set_pg_partman_retention.php');

    $migration->up();
    $migration->up();

    $row = DB::selectOne(
        'SELECT retention, retention_keep_table FROM partman.part_config WHERE parent_table = ?',
        ['public.prc_fetches'],
    );

    expect($row->retention)->toBe(config('partitioning.retention.prc_fetches.months').' months');
    expect((bool) $row->retention_keep_table)->toBeFalse();
});

test('fallback-путь дропает партиции старше retention-окна, когда pg_partman недоступен', function () {
    DB::statement(<<<'SQL'
        CREATE TABLE test_retention_probe (
            id bigint GENERATED ALWAYS AS IDENTITY,
            created_at timestamptz NOT NULL,
            PRIMARY KEY (id, created_at)
        ) PARTITION BY RANGE (created_at)
        SQL);

    $old = Carbon::now()->startOfMonth()->subMonths(8);
    $oldPartition = 'test_retention_probe_'.$old->format('Y_m');

    DB::statement(sprintf(
        "CREATE TABLE %s PARTITION OF test_retention_probe FOR VALUES FROM ('%s') TO ('%s')",
        $oldPartition,
        $old->toDateTimeString(),
        $old->copy()->addMonthNoOverflow()->toDateTimeString(),
    ));

    Config::set('partitioning.tables', ['test_retention_probe' => 'created_at']);
    Config::set('partitioning.premake', 1);
    Config::set('partitioning.retention', [
        'test_retention_probe' => ['months' => 6, 'keep_table' => false],
    ]);

    DB::statement('DROP EXTENSION IF EXISTS pg_partman CASCADE');

    expect(Artisan::call('partitions:maintain'))->toBe(0);

    $exists = DB::selectOne('SELECT to_regclass(?) IS NOT NULL AS exists', [$oldPartition]);

    expect($exists->exists)->toBeFalse();
});

test('fallback-путь НЕ дропает партиции таблицы вне retention-конфига', function () {
    DB::statement(<<<'SQL'
        CREATE TABLE test_retention_keep_probe (
            id bigint GENERATED ALWAYS AS IDENTITY,
            created_at timestamptz NOT NULL,
            PRIMARY KEY (id, created_at)
        ) PARTITION BY RANGE (created_at)
        SQL);

    $old = Carbon::now()->startOfMonth()->subMonths(20);
    $oldPartition = 'test_retention_keep_probe_'.$old->format('Y_m');

    DB::statement(sprintf(
        "CREATE TABLE %s PARTITION OF test_retention_keep_probe FOR VALUES FROM ('%s') TO ('%s')",
        $oldPartition,
        $old->toDateTimeString(),
        $old->copy()->addMonthNoOverflow()->toDateTimeString(),
    ));

    Config::set('partitioning.tables', ['test_retention_keep_probe' => 'created_at']);
    Config::set('partitioning.premake', 1);
    Config::set('partitioning.retention', []);

    DB::statement('DROP EXTENSION IF EXISTS pg_partman CASCADE');

    expect(Artisan::call('partitions:maintain'))->toBe(0);

    $exists = DB::selectOne('SELECT to_regclass(?) IS NOT NULL AS exists', [$oldPartition]);

    expect($exists->exists)->toBeTrue();
});
