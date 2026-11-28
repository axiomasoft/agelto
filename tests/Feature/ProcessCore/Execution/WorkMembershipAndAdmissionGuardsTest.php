<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\Primitives\VersionedPayload;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Governance\ControlMode;
use App\Enums\Procurement\Process\Outcomes\AdmissionStatus;
use App\Enums\Procurement\Process\Outcomes\CheckpointStatus;
use App\Enums\Procurement\Process\Outcomes\ClaimStatus;
use App\Enums\Procurement\Process\Outcomes\CompletionStatus;
use App\Repositories\Procurement\Process\Work\AdmissionRepository;
use App\Repositories\Procurement\Process\Work\WorkStoreRepository;
use App\Services\Procurement\Process\Execution\WorkFeeder;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use App\Services\Procurement\Process\Infrastructure\ProcessPayloadCodec;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FixtureStageCheckpoint;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('claim-recovery-and-invariants', 'admission-fairness');

/** Business ledger snapshot; a separate invariant incident/projection is the only allowed write. */
function workGuardBusinessRows(WorkId $work): array
{
    $id = $work->value->value;
    $groups = DB::table('prc_admission_groups')->where('work_id', $id)->orderBy('id')
        ->selectRaw("*, encode(required_lane_set_hash, 'hex') AS required_lane_set_hash")->get();

    return [
        'work' => (array) DB::table('prc_process_work')->where('id', $id)
            ->selectRaw("*, encode(work_key, 'hex') AS work_key, encode(input_hash, 'hex') AS input_hash")->sole(),
        'groups' => $groups->map(fn (object $row): array => (array) $row)->all(),
        'members' => DB::table('prc_process_reservations')->whereIn('admission_group_id', $groups->pluck('id'))
            ->orderBy('admission_group_id')->orderBy('lane_id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'attempts' => DB::table('prc_process_attempts')->where('work_id', $id)->orderBy('id')
            ->selectRaw("*, encode(owner_token_fingerprint, 'hex') AS owner_token_fingerprint, encode(fault_signature, 'hex') AS fault_signature")
            ->get()->map(fn (object $row): array => (array) $row)->all(),
        'block' => (array) DB::table('prc_crawl_blocks')->where('id', DB::table('prc_process_work')->where('id', $id)->value('crawl_block_id'))
            ->selectRaw("*, encode(filter_hash, 'hex') AS filter_hash")->sole(),
        'links' => DB::table('prc_process_run_work')->where('work_id', $id)->orderBy('run_id')->get()->map(fn (object $row): array => (array) $row)->all(),
        'work_count' => DB::table('prc_process_work')->count(),
        'transitions' => DB::table('prc_process_transitions')->where('work_id', $id)->count(),
        'wakes' => DB::table('prc_process_outbox')->whereIn('kind', ['work_wake', 'feeder_wake'])->count(),
    ];
}

test('identity member work/stage проверяется до claim/finalize и при head drift', function (bool $running, string $field, bool $drift): void {
    $results = FinalizeFixtures::authority([StageCode::Listing])['listing'];
    $work = ExecutionFixtures::listingWork();
    app(WorkFeeder::class)->tick('acquisition');
    $lease = $running ? app(WorkStoreRepository::class)->claim($work, ExecutionFixtures::delivery($work))->lease : null;
    $other = ExecutionFixtures::listingWork();
    $next = LedgerFixtures::crawlBlock();
    $results->successors = [LedgerFixtures::listingInput($next['block'], $next['filter_hash'], $next['access_policy_hash'])];
    $group = DB::table('prc_admission_groups')->where('work_id', $work->value->value)->sole();
    $lane = DB::table('prc_process_lanes')->where('lane_key', 'acquisition.listing')->value('id');
    DB::table('prc_process_reservations')->where('admission_group_id', $group->id)->where('lane_id', $lane)
        ->update([$field => $field === 'work_id' ? $other->value->value : StageCode::Detail->value]);
    if ($drift) {
        DB::update('UPDATE prc_crawl_blocks SET input_revision = input_revision + 1, head_generation = head_generation + 1
                    WHERE id = (SELECT crawl_block_id FROM prc_process_work WHERE id = ?)', [$work->value->value]);
    }
    $before = workGuardBusinessRows($work);
    $result = $running
        ? app(WorkFinalizer::class)->finish($lease ?? throw new LogicException('Fixture claim failed.'), FinalizeFixtures::succeeded(StageCode::Listing, 'wrong-member'))
        : app(WorkStoreRepository::class)->claim($work, ExecutionFixtures::delivery($work));

    expect($result->status)->toBe($running ? CompletionStatus::InvariantViolation : ClaimStatus::InvariantViolation)
        ->and(workGuardBusinessRows($work))->toBe($before)
        ->and([$results->resultWrites, $results->progressWrites])->toBe([0, 0])
        ->and(DB::table('prc_process_incidents')->where('kind', 'admission_membership_mismatch')->count())->toBe(1);
})->with([
    'claim wrong work' => [false, 'work_id', false],
    'claim wrong stage' => [false, 'stage', false],
    'finalize wrong work' => [true, 'work_id', false],
    'finalize wrong stage' => [true, 'stage', false],
    'claim wrong work plus head' => [false, 'work_id', true],
    'claim wrong stage plus head' => [false, 'stage', true],
    'finalize wrong work plus head' => [true, 'work_id', true],
    'finalize wrong stage plus head' => [true, 'stage', true],
]);

test('missing/extra member плюс новый head не обходят guard через supersede', function (bool $running, bool $extra): void {
    $results = FinalizeFixtures::authority([StageCode::Listing])['listing'];
    $work = ExecutionFixtures::listingWork();
    app(WorkFeeder::class)->tick('acquisition');
    $lease = $running ? app(WorkStoreRepository::class)->claim($work, ExecutionFixtures::delivery($work))->lease : null;
    $group = DB::table('prc_admission_groups')->where('work_id', $work->value->value)->sole();
    if ($extra) {
        $lane = DB::table('prc_process_lanes')->where('lane_key', 'acquisition.detail')->value('id');
        DB::insert('INSERT INTO prc_process_reservations (admission_group_id, lane_id, work_id, stage, state) VALUES (?, ?, ?, ?, ?)', [
            $group->id, $lane, $work->value->value, 'listing', $running ? 'running' : 'admitted',
        ]);
    } else {
        $lane = DB::table('prc_process_lanes')->where('lane_key', 'acquisition.listing')->value('id');
        DB::delete('DELETE FROM prc_process_reservations WHERE admission_group_id = ? AND lane_id = ?', [$group->id, $lane]);
    }
    DB::update('UPDATE prc_crawl_blocks SET input_revision = input_revision + 1, head_generation = head_generation + 1
                WHERE id = (SELECT crawl_block_id FROM prc_process_work WHERE id = ?)', [$work->value->value]);
    $before = workGuardBusinessRows($work);
    $result = $running
        ? app(WorkFinalizer::class)->finish($lease ?? throw new LogicException('Fixture claim failed.'), FinalizeFixtures::succeeded(StageCode::Listing, 'corrupt-supersede'))
        : app(WorkStoreRepository::class)->claim($work, ExecutionFixtures::delivery($work));

    expect($result->status)->toBe($running ? CompletionStatus::InvariantViolation : ClaimStatus::InvariantViolation)
        ->and(workGuardBusinessRows($work))->toBe($before)
        ->and([$results->resultWrites, $results->progressWrites])->toBe([0, 0])
        ->and(DB::table('prc_process_incidents')->where('kind', 'admission_membership_mismatch')->count())->toBe(1);
})->with([
    'claim missing member' => [false, false],
    'claim extra member' => [false, true],
    'finalize missing member' => [true, false],
    'finalize extra member' => [true, true],
]);

test('чужой attempt group не разрешает supersede при head drift', function (bool $running): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $other = ExecutionFixtures::listingWork();
    $otherLease = FinalizeFixtures::running($other);
    $work = ExecutionFixtures::listingWork();
    app(WorkFeeder::class)->tick('acquisition');
    $lease = $running ? app(WorkStoreRepository::class)->claim($work, ExecutionFixtures::delivery($work))->lease : null;
    DB::table('prc_admission_groups')->where('work_id', $work->value->value)->update(['attempt_id' => $otherLease->attemptId->value]);
    DB::update('UPDATE prc_crawl_blocks SET input_revision = 2, head_generation = 2
                WHERE id = (SELECT crawl_block_id FROM prc_process_work WHERE id = ?)', [$work->value->value]);
    $before = workGuardBusinessRows($work);
    $otherBefore = workGuardBusinessRows($other);
    $result = $running
        ? app(WorkFinalizer::class)->finish($lease ?? throw new LogicException('Fixture claim failed.'), FinalizeFixtures::succeeded(StageCode::Listing, 'wrong-attempt'))
        : app(WorkStoreRepository::class)->claim($work, ExecutionFixtures::delivery($work));

    expect($result->status)->toBe($running ? CompletionStatus::InvariantViolation : ClaimStatus::InvariantViolation)
        ->and(workGuardBusinessRows($work))->toBe($before)
        ->and(workGuardBusinessRows($other))->toBe($otherBefore)
        ->and(DB::table('prc_process_incidents')->where('kind', 'admission_membership_mismatch')->count())->toBe(1);
})->with([false, true]);

test('ready/due/resume между selection и admit инвалидирует stale count без изменения cursor', function (string $arrival): void {
    ExecutionFixtures::authority([StageCode::Listing], lanes: [
        'acquisition' => ['max_outstanding' => 4, 'max_running' => 4, 'low_watermark' => 3, 'refill_max' => 1],
        'acquisition.listing' => ['max_outstanding' => 4, 'max_running' => 2, 'low_watermark' => 3, 'refill_max' => 1],
    ]);
    $first = array_map(fn (): WorkId => ExecutionFixtures::listingWork('platform:aaa'), range(1, 3));
    // Each tick has one grant; keep the original two A admissions before B becomes eligible.
    expect(app(WorkFeeder::class)->tick('acquisition') + app(WorkFeeder::class)->tick('acquisition'))->toBe(2);
    ExecutionFixtures::ensureWorkScopes(StageCode::Listing, 'platform:bbb');
    $cursorJson = (string) DB::table('prc_process_lanes')->where('lane_key', 'acquisition')->value('fairness_cursor');
    $cursor = app(ProcessPayloadCodec::class)->decodeFairnessCursor(new VersionedPayload($cursorJson, 1));
    $second = ExecutionFixtures::listingWork('platform:bbb');
    if ($arrival === 'resume') {
        ExecutionFixtures::setMode('acquisition:platform:bbb', ControlMode::Paused);
    } elseif ($arrival === 'due') {
        DB::update("UPDATE prc_process_work SET state = 'deferred', reason_code = 'rate_limit', due_at = clock_timestamp() + interval '1 hour' WHERE id = ?", [$second->value->value]);
    }
    // The caller already selected A with count=1. B becomes eligible without an admission/cursor write.
    if ($arrival === 'resume') {
        ExecutionFixtures::setMode('acquisition:platform:bbb', ControlMode::Enabled);
    } elseif ($arrival === 'due') {
        DB::update("UPDATE prc_process_work SET due_at = clock_timestamp() - interval '1 second' WHERE id = ?", [$second->value->value]);
    }
    $before = workGuardBusinessRows($first[2]);

    expect(app(AdmissionRepository::class)->admit($first[2], $cursor, 1)->status)->toBe(AdmissionStatus::CursorMoved)
        ->and(workGuardBusinessRows($first[2]))->toBe($before)
        ->and((string) DB::table('prc_process_lanes')->where('lane_key', 'acquisition')->value('fairness_cursor'))->toBe($cursorJson)
        ->and(app(WorkFeeder::class)->tick('acquisition'))->toBe(1)
        ->and(ExecutionFixtures::work($first[2])->state)->toBe('ready')
        ->and(ExecutionFixtures::work($second)->state)->toBe('admitted')
        ->and(ExecutionFixtures::laneCounts('acquisition')['outstanding'])->toBe(3);
})->with(['ready', 'due', 'resume']);

test('live precheck затем expiry до настоящего checkpoint CAS — typed lost без writes/fault charge', function (): void {
    $results = FinalizeFixtures::authority([StageCode::Listing])['listing'];
    $work = ExecutionFixtures::listingWork();
    $lease = FinalizeFixtures::running($work);
    $armed = true;
    $casReached = false;
    $before = [];
    // Test-only SQL seam, no mocked UPDATE/result. At this point live/seq/head prechecks have passed.
    DB::connection()->beforeExecuting(function (string $sql) use (&$armed, &$casReached, &$before, $work): void {
        if (! $armed || ! str_contains($sql, 'SET checkpoint = ?::jsonb, checkpoint_schema_version = ?, checkpoint_seq = checkpoint_seq + 1')) {
            return;
        }
        $armed = false;
        $casReached = true;
        // Compress remaining lease only after the live precheck: no scheduling-speed assumption.
        DB::update("UPDATE prc_process_work SET lease_until = clock_timestamp() + interval '0.05 second' WHERE id = ?", [$work->value->value]);
        $before = workGuardBusinessRows($work);
        DB::selectOne('SELECT pg_sleep(0.06)');
    });
    try {
        $result = app(WorkStoreRepository::class)->checkpoint($lease, new FixtureStageCheckpoint(7), 0);
    } finally {
        $armed = false;
    }

    expect($casReached)->toBeTrue()
        ->and($result->status)->toBe(CheckpointStatus::LostOwnership)
        ->and(workGuardBusinessRows($work))->toBe($before)
        ->and($results->progressWrites)->toBe(0)
        ->and(DB::table('prc_process_attempts')->where('work_id', $work->value->value)->whereNotNull('fault_signature')->count())->toBe(0)
        ->and(DB::table('prc_process_attempts')->where('id', $lease->attemptId->value)->value('last_checkpoint_at'))->toBeNull()
        ->and(DB::table('prc_process_incidents')->count())->toBe(0);
});
