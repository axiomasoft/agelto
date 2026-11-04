<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\DeliveryGeneration;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\ClaimStatus;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\StageCode;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Process\WorkFeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\ExecutionFixtures;

pest()->group('pause-claim-fence', 'admission-fairness');

// Отдельные PHP процессы и PostgreSQL connections коммитят rows: Process ledger isolated
// agelto_test очищается целиком до и после, чтобы RefreshDatabase suites видели чистый baseline.
beforeEach(fn () => ExecutionFixtures::truncateLedger());
afterEach(fn () => ExecutionFixtures::truncateLedger());

function pauseClaimWorker(WorkId $work): string
{
    return sprintf(
        '\Tests\Support\ProcessCore\ExecutionFixtures::registerHandler(\App\Enums\Procurement\Process\StageCode::Listing);'
        .'$result = app(\\App\\Repositories\\Procurement\\Process\\WorkStoreRepository::class)->claim('
        ."new \\App\\Dto\\Procurement\\Process\\WorkId(new \\App\\Dto\\Procurement\\Process\\ProcessUuid('%s')), new \\App\\Dto\\Procurement\\Process\\DeliveryGeneration(1));"
        ."return ['status' => \$result->status->value, 'pid' => \\Illuminate\\Support\\Facades\\DB::selectOne('SELECT pg_backend_pid() AS pid')->pid];",
        $work->value->value,
    );
}

test('pause scope во время 10 параллельных claim: без deadlock, ни одного grant после commit pause', function (): void {
    ExecutionFixtures::authority([StageCode::Listing], lanes: [
        'acquisition' => ['max_outstanding' => 20, 'max_running' => 10, 'low_watermark' => 19, 'refill_max' => 10],
        'acquisition.listing' => ['max_outstanding' => 20, 'max_running' => 10, 'low_watermark' => 19, 'refill_max' => 10],
    ]);
    $works = array_map(fn (): WorkId => ExecutionFixtures::listingWork(), range(1, 10));
    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(10);

    $workers = [];
    foreach ($works as $index => $work) {
        $workers['claim'.$index] = pauseClaimWorker($work);
    }
    $workers['pause'] = "\$result = \\Tests\\Support\\ProcessCore\\ExecutionFixtures::setMode('acquisition', \\App\\Enums\\Procurement\\Process\\ControlMode::Paused);"
        ."return ['status' => \$result->status->value, 'pid' => \\Illuminate\\Support\\Facades\\DB::selectOne('SELECT pg_backend_pid() AS pid')->pid];";
    $results = ConcurrentTransactions::concurrently($workers);

    $claims = array_filter($results, fn (string $name): bool => $name !== 'pause', ARRAY_FILTER_USE_KEY);
    $statuses = array_count_values(array_column($claims, 'status'));
    $claimed = $statuses[ClaimStatus::Claimed->value] ?? 0;
    $lateGrants = DB::selectOne(
        "SELECT count(*) AS late FROM prc_process_attempts a
         WHERE a.started_at >= (SELECT changed_at FROM prc_control_scopes WHERE scope_key = 'acquisition')",
    )->late;

    expect($results['pause']['status'])->toBe('applied')
        ->and(count(array_unique(array_column($results, 'pid'))))->toBe(11)
        ->and(array_diff(array_keys($statuses), [ClaimStatus::Claimed->value, ClaimStatus::Paused->value]))->toBe([])
        ->and(array_sum($statuses))->toBe(10)
        ->and($lateGrants)->toBe(0)
        ->and(DB::table('prc_process_attempts')->count())->toBe($claimed)
        ->and(DB::table('prc_process_work')->where('state', 'running')->count())->toBe($claimed)
        ->and(DB::table('prc_process_work')->where('state', 'admitted')->count())->toBe(10 - $claimed)
        ->and(DB::table('prc_admission_groups')->where('state', 'running')->count())->toBe($claimed)
        ->and(DB::table('prc_process_reservations')->where('state', 'running')->count())->toBe(2 * $claimed)
        ->and(DB::table('prc_process_reservations')->count())->toBe(20);

    // После committed pause новые grants запрещены и feeder, и claim; resume возвращает eligibility.
    $fresh = ExecutionFixtures::listingWork();
    expect(app(WorkFeeder::class)->tick('acquisition'))->toBe(0)
        ->and(ExecutionFixtures::work($fresh)->state)->toBe('ready');
    $waiting = array_values(array_filter($works, fn (WorkId $work): bool => ExecutionFixtures::work($work)->state === 'admitted'));
    foreach ($waiting as $work) {
        expect(app(WorkStoreRepository::class)->claim($work, new DeliveryGeneration(1))->status)->toBe(ClaimStatus::Paused);
    }
    expect(ExecutionFixtures::setMode('acquisition', ControlMode::Enabled)->controlVersion)->toBe(3)
        ->and(app(WorkFeeder::class)->tick('acquisition'))->toBe(1);
});

test('два feeder одновременно: admitted ≤ W parent, одна group и один wake на work', function (): void {
    ExecutionFixtures::authority([StageCode::Listing], lanes: [
        'acquisition' => ['max_outstanding' => 3, 'max_running' => 3, 'low_watermark' => 2, 'refill_max' => 3],
        'acquisition.listing' => ['max_outstanding' => 3, 'max_running' => 3, 'low_watermark' => 2, 'refill_max' => 3],
    ]);
    foreach (range(1, 6) as $ignored) {
        ExecutionFixtures::listingWork('platform:aaa');
    }
    $feeder = '\Tests\Support\ProcessCore\ExecutionFixtures::registerHandler(\App\Enums\Procurement\Process\StageCode::Listing);'
        ."return ['granted' => app(\\App\\Services\\Procurement\\Process\\WorkFeeder::class)->tick('acquisition')];";

    $results = ConcurrentTransactions::concurrently(['first' => $feeder, 'second' => $feeder]);
    $groups = DB::table('prc_admission_groups')->where('state', 'admitted')->get();

    expect($results['first']['granted'] + $results['second']['granted'])->toBe(3)
        ->and(ExecutionFixtures::laneCounts('acquisition'))->toBe(['outstanding' => 3, 'running' => 0])
        ->and($groups)->toHaveCount(3)
        ->and($groups->pluck('work_id')->unique())->toHaveCount(3)
        ->and(DB::table('prc_process_work')->where('state', 'admitted')->pluck('dispatch_generation')->unique()->values()->all())->toBe([1])
        ->and(DB::table('prc_process_outbox')->where('kind', 'work_wake')->count())->toBe(3)
        ->and(DB::table('prc_control_scopes')->where('scope_key', 'acquisition:platform:aaa')->count())->toBe(1)
        ->and(json_decode((string) DB::table('prc_process_lanes')->where('lane_key', 'acquisition')->value('fairness_cursor'), true))
        ->toBe(['last_cohort' => 'platform:aaa', 'last_stage_by_cohort' => ['platform:aaa' => 'listing']]);
});
