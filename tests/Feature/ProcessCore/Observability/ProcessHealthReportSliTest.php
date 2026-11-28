<?php

declare(strict_types=1);

use App\Models\Procurement\Estimate\Estimate;
use App\Services\Procurement\Process\Execution\ProcessAuthoritySync;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('acquisition-operations');

test('пустой отчёт возвращает нулевую долю просроченных аренд и неизвестную долю валидных смет', function (): void {
    expect(Artisan::call('process:health-report', ['--json' => true]))->toBe(0);
    $report = json_decode(trim(Artisan::output()), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($report['work'])->toBe([])
        ->and($report['sli']['oldest_waiting_by_lane'])->toBe([])
        ->and($report['sli']['stale_lease'])->toBe(['running_total' => 0, 'stale_running' => 0, 'stale_lease_rate' => 0.0])
        ->and($report['sli']['estimate_validation'])->toBe(['total_estimates' => 0, 'valid_estimates' => 0, 'valid_rate' => null])
        ->and(array_column($report['sli']['pg_dead_tuples'], 'relname'))->toBe(['prc_process_outbox', 'prc_process_work']);
});

test('отчёт показывает возраст ожидания по обязательным lanes, просроченную аренду и долю валидных смет', function (): void {
    app(ProcessAuthoritySync::class)->ensureBaseline();
    $fixture = LedgerFixtures::crawlBlock();
    LedgerFixtures::insertRawWork($fixture['block'], ['created_at' => DB::raw("clock_timestamp() - interval '2 minutes'")]);
    LedgerFixtures::insertRawWork($fixture['block'], [
        'state' => 'deferred',
        'reason_code' => 'item_retry',
        'due_at' => DB::raw("clock_timestamp() + interval '1 minute'"),
        'created_at' => DB::raw("clock_timestamp() - interval '1 minute'"),
    ]);
    LedgerFixtures::insertRawWork($fixture['block'], [
        'state' => 'running',
        'owner_token' => LedgerFixtures::uuid()->value,
        'lease_until' => DB::raw("clock_timestamp() - interval '1 minute'"),
    ]);
    LedgerFixtures::insertRawWork($fixture['block'], [
        'state' => 'running',
        'owner_token' => LedgerFixtures::uuid()->value,
        'lease_until' => DB::raw("clock_timestamp() + interval '1 minute'"),
    ]);
    Estimate::factory()->create(['validation_status' => 'valid']);
    Estimate::factory()->create(['validation_status' => 'invalid']);

    expect(Artisan::call('process:health-report', ['--json' => true]))->toBe(0);
    $report = json_decode(trim(Artisan::output()), associative: true, flags: JSON_THROW_ON_ERROR);
    $lanes = collect($report['sli']['oldest_waiting_by_lane'])->keyBy('lane_key');
    $expectedLaneIds = DB::table('prc_process_lanes')->whereIn('lane_key', ['acquisition', 'acquisition.listing'])->pluck('id', 'lane_key');

    expect($report['work'])->toBe([
        ['stage' => 'listing', 'state' => 'deferred', 'reason_code' => 'item_retry', 'total' => 1],
        ['stage' => 'listing', 'state' => 'ready', 'reason_code' => null, 'total' => 1],
        ['stage' => 'listing', 'state' => 'running', 'reason_code' => null, 'total' => 2],
    ])
        ->and($lanes->keys()->sort()->values()->all())->toBe(['acquisition', 'acquisition.listing'])
        ->and($lanes['acquisition']['lane_id'])->toBe($expectedLaneIds['acquisition'])
        ->and($lanes['acquisition.listing']['lane_id'])->toBe($expectedLaneIds['acquisition.listing'])
        ->and($lanes['acquisition']['waiting_count'])->toBe(2)
        ->and($lanes['acquisition.listing']['waiting_count'])->toBe(2)
        ->and($lanes['acquisition']['oldest_waiting_seconds'])->toBeGreaterThanOrEqual(120)
        ->and($lanes['acquisition.listing']['oldest_waiting_seconds'])->toBeGreaterThanOrEqual(120)
        ->and($report['sli']['stale_lease'])->toBe(['running_total' => 2, 'stale_running' => 1, 'stale_lease_rate' => 0.5])
        ->and($report['sli']['estimate_validation'])->toBe(['total_estimates' => 2, 'valid_estimates' => 1, 'valid_rate' => 0.5])
        ->and(array_column($report['sli']['pg_dead_tuples'], 'relname'))->toBe(['prc_process_outbox', 'prc_process_work']);
});

test('текстовый отчёт дополняется SLI только с флагом и сохраняет прежние строки работы', function (): void {
    $fixture = LedgerFixtures::crawlBlock();
    LedgerFixtures::insertRawWork($fixture['block']);

    expect(Artisan::call('process:health-report'))->toBe(0);
    $plain = trim(Artisan::output());
    expect($plain)->toBe('listing ready eligible 1');

    expect(Artisan::call('process:health-report', ['--sli' => true]))->toBe(0);
    $withSli = trim(Artisan::output());
    expect($withSli)->toStartWith($plain."\n")
        ->toContain('sli.stale_lease running_total=0 stale_running=0 stale_lease_rate=0')
        ->toContain('sli.pg_dead_tuples relname=prc_process_outbox')
        ->toContain('sli.estimate_validation total_estimates=0 valid_estimates=0 valid_rate=null');
});
