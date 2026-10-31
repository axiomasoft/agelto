<?php

declare(strict_types=1);

use App\Actions\Procurement\Process\CancelWork;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\ClaimStatus;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\WorkMutationStatus;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Process\WorkFeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ExecutionFixtures as F;

beforeEach(fn () => F::authority([StageCode::Listing]));

function admittedStopWork(): WorkId
{
    $work = F::listingWork();
    app(WorkFeeder::class)->tick('acquisition');

    return $work;
}

test('enabled Process authority grants a claim without a stop signal', function (): void {
    $work = admittedStopWork();
    $claim = app(WorkStoreRepository::class)->claim($work, F::delivery($work));
    expect($claim->status)->toBe(ClaimStatus::Claimed)
        ->and(F::work($work)->state)->toBe('running')
        ->and(DB::table('prc_process_attempts')->count())->toBe(1);
});

test('Process pause refuses the next claim and preserves its admitted reservation', function (): void {
    $work = admittedStopWork();
    F::setMode('acquisition', ControlMode::Paused);
    expect(app(WorkStoreRepository::class)->claim($work, F::delivery($work))->status)->toBe(ClaimStatus::Paused)
        ->and(F::work($work)->state)->toBe('admitted')
        ->and(DB::table('prc_admission_groups')->where('work_id', $work->value->value)->value('state'))->toBe('admitted')
        ->and(DB::table('prc_process_attempts')->count())->toBe(0);
});

test('Process hard cancel fences the work and releases the whole admission group', function (): void {
    $work = admittedStopWork();
    $before = F::work($work);
    $result = app(CancelWork::class)->execute($work, 'operator stop', $before->transition_seq);
    expect($result->status)->toBe(WorkMutationStatus::Applied)
        ->and(F::work($work)->state)->toBe('cancelled')
        ->and(F::work($work)->generation)->toBe($before->generation + 1)
        ->and(DB::table('prc_admission_groups')->where('work_id', $work->value->value)->value('state'))->toBe('released');
});

test('a stale work snapshot and delivery cannot hide a newly committed cancel', function (): void {
    $work = admittedStopWork();
    $before = F::work($work);
    $delivery = F::delivery($work);
    $works = app(WorkStoreRepository::class);
    app(CancelWork::class)->execute($work, 'cancel after snapshot', $before->transition_seq);
    expect($before->state)->toBe('admitted')
        ->and($works->claim($work, $delivery)->status)->toBe(ClaimStatus::WorkTerminal)
        ->and(F::work($work)->state)->toBe('cancelled')
        ->and(DB::table('prc_process_attempts')->count())->toBe(0);
});
