<?php

declare(strict_types=1);

use App\Models\Procurement\ProcurementEvent;
use App\Models\Procurement\ProcurementRevision;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('fresh schema has a continuous rolling historical and premake window for every configured partitioned table', function () {
    $start = now('UTC')->startOfMonth()->subMonthsNoOverflow(12);
    $end = now('UTC')->startOfMonth()->addMonthsNoOverflow(config('partitioning.premake'));

    foreach (array_keys(config('partitioning.tables')) as $table) {
        expect(array_diff(monthRange($table, $start, $end), partitionMonths($table)))->toBeEmpty();
    }
});

test('repair migration fills a later installation month idempotently and rolls back only marked partitions', function () {
    $migration = include base_path('database/migrations/2026_09_10_100093_repair_partition_coverage.php');

    DB::statement(<<<'SQL'
        CREATE TABLE test_partition_coverage_probe (
            id bigint GENERATED ALWAYS AS IDENTITY,
            created_at timestamptz NOT NULL,
            PRIMARY KEY (id, created_at)
        ) PARTITION BY RANGE (created_at)
        SQL);
    config()->set('partitioning.tables', ['test_partition_coverage_probe' => 'created_at']);
    config()->set('partitioning.premake', 0);

    Carbon::setTestNow('2026-10-01 00:00:00 UTC');
    $migration->up();
    $migration->up();

    expect(partitionMonths('test_partition_coverage_probe'))
        ->toEqual(monthRange('test_partition_coverage_probe', Carbon::parse('2025-10-01 UTC'), Carbon::parse('2026-10-01 UTC')));

    $migration->down();

    expect(DB::selectOne('SELECT to_regclass(?) IS NOT NULL AS exists', ['test_partition_coverage_probe_2026_10'])->exists)->toBeFalse();
});

test('last historical month keeps domain events and revisions without legacy fetch schema', function () {
    $historicalMonth = now('UTC')->startOfMonth()->subMonthNoOverflow()->addDay();

    ProcurementEvent::factory()->create(['occurred_at' => $historicalMonth]);
    ProcurementRevision::factory()->create(['detected_at' => $historicalMonth]);

    expect(Schema::hasTable('prc_fetches'))->toBeFalse()
        ->and(DB::table('prc_events')->where('occurred_at', $historicalMonth)->count())->toBe(1)
        ->and(DB::table('prc_revisions')->where('detected_at', $historicalMonth)->count())->toBe(1);
});

test('все месяцы фиксированного корпуса 2025 принимают доменные события и переживают maintenance', function () {
    foreach (range(1, 12) as $month) {
        ProcurementEvent::factory()->create([
            'occurred_at' => Carbon::create(year: 2025, month: $month, day: 15, timezone: 'UTC'),
        ]);
    }

    expect(DB::table('prc_events')->count())->toBe(12)
        ->and(Artisan::call('partitions:maintain'))->toBe(0)
        ->and(DB::table('prc_events')->count())->toBe(12);

    foreach (range(1, 12) as $month) {
        expect(DB::selectOne('SELECT to_regclass(?) IS NOT NULL AS exists', [sprintf('prc_events_2025_%02d', $month)])->exists)->toBeTrue();
    }
});

function partitionMonths(string $table): array
{
    $rows = DB::select(
        'SELECT child.relname AS name FROM pg_inherits inheritance '
        .'JOIN pg_class child ON child.oid = inheritance.inhrelid '
        .'JOIN pg_class parent ON parent.oid = inheritance.inhparent '
        .'WHERE parent.relname = ? ORDER BY child.relname',
        [$table],
    );

    return collect($rows)
        ->map(fn (object $row): string => $row->name)
        ->filter(fn (string $name): bool => preg_match('/_(\d{4})_(\d{2})$/', $name) === 1)
        ->sort()
        ->values()
        ->all();
}

function monthRange(string $table, Carbon $start, Carbon $end): array
{
    $months = [];

    while ($start->lte($end)) {
        $months[] = $table.'_'.$start->format('Y_m');
        $start = $start->copy()->addMonthNoOverflow();
    }

    return $months;
}
