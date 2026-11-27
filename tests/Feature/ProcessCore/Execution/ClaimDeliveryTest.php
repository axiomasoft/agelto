<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\Authority\ControlChangeResult;
use App\Dto\Procurement\Process\Authority\FairnessCursor;
use App\Dto\Procurement\Process\Primitives\DeliveryGeneration;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Governance\ControlChangeStatus;
use App\Enums\Procurement\Process\Governance\ControlMode;
use App\Enums\Procurement\Process\Outcomes\AdmissionStatus;
use App\Enums\Procurement\Process\Outcomes\ClaimStatus;
use App\Repositories\Procurement\Process\Authority\ControlRepository;
use App\Repositories\Procurement\Process\Work\AdmissionRepository;
use App\Repositories\Procurement\Process\Work\WorkStoreRepository;
use App\Services\Procurement\Process\Execution\WorkFeeder;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('claim-recovery-and-invariants', 'work-controls-and-fences');

function claimAdmitted(WorkId $work): void
{
    expect(DB::table('prc_process_work')->where('id', $work->value->value)->value('state'))->toBe('admitted');
}

function claimTick(): int
{
    return app(WorkFeeder::class)->tick('acquisition');
}

/** Lock tier каждого locking SELECT по порядку исполнения внутри operation. */
function claimLockTiers(Closure $operation): array
{
    $tiers = [];
    DB::listen(function (QueryExecuted $query) use (&$tiers): void {
        if (preg_match('/FOR (SHARE|UPDATE)/', $query->sql) !== 1) {
            return;
        }
        $tiers[] = match (true) {
            str_contains($query->sql, 'prc_control_scopes') => 1,
            str_contains($query->sql, 'prc_process_handler_holds') => 2,
            str_contains($query->sql, 'prc_process_lanes') => 3,
            (bool) preg_match('/FROM (prc_crawl_blocks|prc_procurements|prc_documents) /', $query->sql) => 4,
            str_contains($query->sql, 'prc_process_work') => 5,
            default => 99,
        };
    });
    $operation();

    return $tiers;
}

test('claim admitted→running: generation+1, token, immutable attempt с handler version, DB lease и вся group running', function (): void {
    ExecutionFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();
    claimTick();

    $result = app(WorkStoreRepository::class)->claim($work, new DeliveryGeneration(1));
    $lease = $result->lease;
    $id = $work->value->value;
    $row = DB::selectOne(
        'SELECT state, generation, owner_token, dispatch_claim_until, execution_attempts, transition_seq, item_failures,
                extract(epoch FROM lease_until - clock_timestamp()) AS lease_left, heartbeat_at IS NOT NULL AS heartbeat
         FROM prc_process_work WHERE id = ?',
        [$id],
    );
    $attempt = DB::selectOne("SELECT id, generation, handler_version, encode(owner_token_fingerprint, 'hex') AS fingerprint, finished_at, worker_pid FROM prc_process_attempts WHERE work_id = ?", [$id]);
    $group = DB::table('prc_admission_groups')->where('work_id', $id)->sole();
    $transition = DB::table('prc_process_transitions')->where('work_id', $id)->orderByDesc('entity_seq')->first();

    expect($result->status)->toBe(ClaimStatus::Claimed)
        ->and([$lease->generation, $lease->attemptId->value, $lease->inputRevision, $lease->checkpointSeq, $lease->checkpoint])->toBe([1, $attempt->id, 1, 0, null])
        ->and([$row->state, $row->generation, $row->owner_token, $row->dispatch_claim_until, $row->execution_attempts, $row->transition_seq, $row->item_failures])
        ->toBe(['running', 1, $lease->token->value, null, 1, 3, 0])
        ->and((float) $row->lease_left)->toBeGreaterThan(350.0)->toBeLessThanOrEqual(360.0)
        ->and($row->heartbeat)->toBeTrue()
        ->and([$attempt->generation, $attempt->handler_version, $attempt->fingerprint, $attempt->finished_at])
        ->toBe([1, 'fixture-listing/1', hash('sha256', $lease->token->value), null])
        ->and($attempt->worker_pid)->toBe(getmypid())
        ->and([$group->state, $group->attempt_id, $group->version, $group->dispatch_generation])->toBe(['running', $attempt->id, 2, 1])
        ->and(DB::table('prc_process_reservations')->where('admission_group_id', $group->id)->pluck('state')->all())->toBe(['running', 'running'])
        ->and(ExecutionFixtures::laneCounts('acquisition'))->toBe(['outstanding' => 1, 'running' => 1])
        ->and([$transition->previous_state, $transition->new_state, $transition->actor_kind, $transition->generation])->toBe(['admitted', 'running', 'worker', 1])
        ->and(DB::table('prc_process_outbox')->where('dedup_key', 'project:'.$transition->event_id)->count())->toBe(1);
});

test('duplicate, stale, expired и terminal delivery дают ACK без attempt и без изменения work', function (): void {
    ExecutionFixtures::authority([StageCode::Listing, StageCode::Detail]);
    $running = ExecutionFixtures::listingWork();
    $expired = ExecutionFixtures::listingWork();
    $stale = ExecutionFixtures::listingWork();
    $terminal = ExecutionFixtures::detailWork();
    expect(claimTick())->toBe(4);
    $works = app(WorkStoreRepository::class);
    expect($works->claim($running, new DeliveryGeneration(1))->status)->toBe(ClaimStatus::Claimed);
    DB::update("UPDATE prc_process_work SET dispatch_claim_until = clock_timestamp() - interval '1 second' WHERE id = ?", [$expired->value->value]);
    DB::update('UPDATE prc_procurements SET head_generation = 2 WHERE id = (SELECT procurement_id FROM prc_process_work WHERE id = ?)', [$terminal->value->value]);
    $before = DB::table('prc_process_work')->orderBy('id')->get(['id', 'state', 'generation', 'dispatch_generation', 'transition_seq'])->keyBy('id');

    expect($works->claim($running, new DeliveryGeneration(1))->status)->toBe(ClaimStatus::DuplicateDelivery)
        ->and($works->claim($running, new DeliveryGeneration(2))->status)->toBe(ClaimStatus::StaleDelivery)
        ->and($works->claim($expired, new DeliveryGeneration(1))->status)->toBe(ClaimStatus::ClaimExpired)
        ->and($works->claim($stale, new DeliveryGeneration(2))->status)->toBe(ClaimStatus::StaleDelivery)
        ->and(DB::table('prc_process_attempts')->count())->toBe(1)
        ->and(DB::table('prc_process_work')->whereIn('id', [$running->value->value, $expired->value->value, $stale->value->value])->orderBy('id')
            ->get(['id', 'state', 'generation', 'dispatch_generation', 'transition_seq'])->keyBy('id')->all())
        ->toEqual($before->only([$running->value->value, $expired->value->value, $stale->value->value])->all());

    // Stale input под locks claim: fenced supersede, release всей group; повторная delivery — terminal.
    expect($works->claim($terminal, new DeliveryGeneration(1))->status)->toBe(ClaimStatus::WorkTerminal);
    $row = ExecutionFixtures::work($terminal);
    $group = DB::table('prc_admission_groups')->where('work_id', $terminal->value->value)->sole();
    expect([$row->state, $row->reason_code, $row->generation])->toBe(['superseded', 'input_invalidated', 1])
        ->and([$group->state, $group->release_reason, $group->version])->toBe(['released', 'superseded', 2])
        ->and(DB::table('prc_process_reservations')->where('work_id', $terminal->value->value)->count())->toBe(0)
        ->and($works->claim($terminal, new DeliveryGeneration(1))->status)->toBe(ClaimStatus::WorkTerminal)
        ->and(DB::table('prc_process_attempts')->count())->toBe(1);
});

test('C parent и child: CapacityWait сохраняет group, даёт новый dispatch generation и отложенный wake, старая delivery stale', function (): void {
    ExecutionFixtures::authority([StageCode::Listing, StageCode::Detail], lanes: [
        'acquisition' => ['max_outstanding' => 5, 'max_running' => 2, 'low_watermark' => 4, 'refill_max' => 5],
        'acquisition.listing' => ['max_outstanding' => 5, 'max_running' => 1, 'low_watermark' => 4, 'refill_max' => 5],
    ]);
    [$listingA, $listingB] = [ExecutionFixtures::listingWork(), ExecutionFixtures::listingWork()];
    [$detailA, $detailB] = [ExecutionFixtures::detailWork(), ExecutionFixtures::detailWork()];
    expect(claimTick())->toBe(4);
    $works = app(WorkStoreRepository::class);

    expect($works->claim($listingA, ExecutionFixtures::delivery($listingA))->status)->toBe(ClaimStatus::Claimed)
        ->and($works->claim($listingB, new DeliveryGeneration(1))->status)->toBe(ClaimStatus::CapacityWait);

    $id = $listingB->value->value;
    $group = DB::table('prc_admission_groups')->where('work_id', $id)->sole();
    $wake = DB::selectOne('SELECT extract(epoch FROM available_at - clock_timestamp()) AS delay FROM prc_process_outbox WHERE dedup_key = ?', ['wake:'.$id.':2']);
    $transition = DB::table('prc_process_transitions')->where('work_id', $id)->orderByDesc('entity_seq')->first();
    $row = ExecutionFixtures::work($listingB);

    expect([$row->state, $row->dispatch_generation, $row->generation])->toBe(['admitted', 2, 0])
        ->and([$group->state, $group->dispatch_generation, $group->version])->toBe(['admitted', 2, 2])
        ->and(DB::table('prc_process_reservations')->where('admission_group_id', $group->id)->pluck('state')->all())->toBe(['admitted', 'admitted'])
        ->and((float) $wake->delay)->toBeGreaterThan(4.0)->toBeLessThanOrEqual(10.0)
        ->and([$transition->previous_state, $transition->new_state, $transition->reason_code])->toBe(['admitted', 'admitted', 'capacity_wait'])
        ->and(DB::table('prc_process_work')->where('id', $id)->value('item_failures'))->toBe(0)
        ->and($works->claim($listingB, new DeliveryGeneration(1))->status)->toBe(ClaimStatus::StaleDelivery);

    // Parent C=2: второй running detail занимает последний parent slot, третий ждёт.
    expect($works->claim($detailA, ExecutionFixtures::delivery($detailA))->status)->toBe(ClaimStatus::Claimed)
        ->and($works->claim($detailB, ExecutionFixtures::delivery($detailB))->status)->toBe(ClaimStatus::CapacityWait)
        ->and(ExecutionFixtures::laneCounts('acquisition'))->toBe(['outstanding' => 4, 'running' => 2])
        ->and(ExecutionFixtures::laneCounts('acquisition.listing'))->toBe(['outstanding' => 2, 'running' => 1])
        ->and(DB::table('prc_admission_groups')->count())->toBe(4)
        ->and(DB::table('prc_process_attempts')->count())->toBe(2);
});

test('pause и handler hold при claim: Paused/HandlerHeld без изменения admitted group; resume разрешает claim', function (): void {
    ExecutionFixtures::authority([StageCode::Listing]);
    $paused = ExecutionFixtures::listingWork();
    $held = ExecutionFixtures::listingWork();
    expect(claimTick())->toBe(2);
    $works = app(WorkStoreRepository::class);

    expect(ExecutionFixtures::setMode('acquisition', ControlMode::Paused)->status)->toBe(ControlChangeStatus::Applied)
        ->and($works->claim($paused, new DeliveryGeneration(1))->status)->toBe(ClaimStatus::Paused)
        ->and(DB::table('prc_admission_groups')->where('work_id', $paused->value->value)->value('state'))->toBe('admitted')
        ->and(ExecutionFixtures::work($paused)->transition_seq)->toBe(2);
    claimAdmitted($paused);

    expect(ExecutionFixtures::setMode('acquisition', ControlMode::Enabled)->status)->toBe(ControlChangeStatus::Applied)
        ->and($works->claim($paused, new DeliveryGeneration(1))->status)->toBe(ClaimStatus::Claimed);

    ExecutionFixtures::holdHandler(StageCode::Listing);
    expect($works->claim($held, new DeliveryGeneration(1))->status)->toBe(ClaimStatus::HandlerHeld)
        ->and(DB::table('prc_admission_groups')->where('work_id', $held->value->value)->value('state'))->toBe('admitted')
        ->and(DB::table('prc_process_attempts')->count())->toBe(1);
    claimAdmitted($held);
});

test('нарушение exact membership: InvariantViolation и incident без молчаливого repair', function (): void {
    ExecutionFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();
    claimTick();
    $group = DB::table('prc_admission_groups')->where('work_id', $work->value->value)->sole();
    $child = DB::table('prc_process_lanes')->where('lane_key', 'acquisition.listing')->value('id');
    DB::delete('DELETE FROM prc_process_reservations WHERE admission_group_id = ? AND lane_id = ?', [$group->id, $child]);
    $works = app(WorkStoreRepository::class);

    expect($works->claim($work, new DeliveryGeneration(1))->status)->toBe(ClaimStatus::InvariantViolation)
        ->and($works->claim($work, new DeliveryGeneration(1))->status)->toBe(ClaimStatus::InvariantViolation);

    $incident = DB::table('prc_process_incidents')->sole();
    expect([$incident->kind, $incident->state, $incident->occurrences, $incident->scope_key])->toBe(['admission_membership_mismatch', 'open', 2, 'lane:acquisition'])
        ->and(DB::table('prc_process_transitions')->where('incident_id', $incident->id)->value('new_state'))->toBe('open')
        ->and(DB::table('prc_process_reservations')->where('admission_group_id', $group->id)->count())->toBe(1)
        ->and(DB::table('prc_process_attempts')->count())->toBe(0);
    claimAdmitted($work);
});

test('admission с active group у ready work — invariant incident, не второй group', function (): void {
    ExecutionFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();
    ExecutionFixtures::ensureWorkScopes(StageCode::Listing, 'manual');
    DB::insert(
        "INSERT INTO prc_admission_groups (id, work_id, dispatch_generation, required_lane_set_hash, state, admitted_at)
         VALUES (?, ?, 1, decode(?, 'hex'), 'admitted', clock_timestamp())",
        [LedgerFixtures::uuid()->value, $work->value->value, ExecutionFixtures::hash('lanes')->hex],
    );

    expect(app(AdmissionRepository::class)->admit($work, FairnessCursor::empty(), 1)->status)->toBe(AdmissionStatus::InvariantViolation)
        ->and(DB::table('prc_admission_groups')->where('work_id', $work->value->value)->count())->toBe(1)
        ->and(DB::table('prc_process_incidents')->value('kind'))->toBe('admission_membership_mismatch')
        ->and(ExecutionFixtures::work($work)->state)->toBe('ready');
});

test('admission и claim берут full prefix control → handler → capacity → subject → work без позднего более раннего lock', function (): void {
    ExecutionFixtures::authority([StageCode::Detail]);
    $work = ExecutionFixtures::detailWork('platform:aaa');
    ExecutionFixtures::ensureWorkScopes(StageCode::Detail, 'platform:aaa');

    $admit = claimLockTiers(fn () => expect(app(AdmissionRepository::class)->admit($work, FairnessCursor::empty(), 1)->status)->toBe(AdmissionStatus::Admitted));
    $claim = claimLockTiers(fn () => expect(app(WorkStoreRepository::class)->claim($work, new DeliveryGeneration(1))->status)->toBe(ClaimStatus::Claimed));

    foreach ([$admit, $claim] as $tiers) {
        $sorted = $tiers;
        sort($sorted);
        expect($tiers)->toBe($sorted)
            ->and(array_values(array_unique($tiers)))->toBe([1, 2, 3, 4, 5])
            ->and(end($tiers))->toBe(5);
    }
});

test('setMode: optimistic version, unchanged без записи, control transition; resume снимает только manual mode', function (): void {
    ExecutionFixtures::authority([StageCode::Listing]);
    ExecutionFixtures::holdHandler(StageCode::Listing);
    $scope = DB::table('prc_control_scopes')->where('scope_key', 'acquisition')->sole();

    $paused = ExecutionFixtures::setMode('acquisition', ControlMode::Paused);
    $again = ExecutionFixtures::setMode('acquisition', ControlMode::Paused);
    $change = fn (string $key, ControlMode $mode, int $expected): ControlChangeResult => app(ProcessTransaction::class)->run(
        fn (): ControlChangeResult => app(ControlRepository::class)->setMode($key, $mode, null, 'operator view', $expected, LedgerFixtures::uuid(), LedgerFixtures::uuid()),
    );
    $conflict = $change('acquisition', ControlMode::Enabled, 1);
    $missing = $change('acquisition:platform:unknown', ControlMode::Paused, 1);
    $resumed = ExecutionFixtures::setMode('acquisition', ControlMode::Enabled);
    $transitions = DB::table('prc_process_transitions')->where('control_id', $scope->id)->orderBy('entity_seq')->get();

    expect([$paused->status, $paused->controlVersion])->toBe([ControlChangeStatus::Applied, 2])
        ->and([$again->status, $again->controlVersion])->toBe([ControlChangeStatus::Unchanged, 2])
        ->and([$conflict->status, $conflict->controlVersion])->toBe([ControlChangeStatus::VersionConflict, 2])
        ->and([$missing->status, $missing->controlVersion])->toBe([ControlChangeStatus::NotFound, null])
        ->and([$resumed->status, $resumed->controlVersion])->toBe([ControlChangeStatus::Applied, 3])
        ->and($transitions->map(fn ($row): array => [$row->entity_seq, $row->previous_state, $row->new_state, $row->reason_code])->all())
        ->toBe([[2, 'enabled', 'paused', 'manual_paused'], [3, 'paused', 'enabled', 'manual_enabled']])
        ->and(DB::table('prc_control_scopes')->where('scope_key', 'acquisition')->value('desired_mode'))->toBe('enabled')
        ->and(DB::table('prc_process_handler_holds')->where('stage', 'listing')->value('state'))->toBe('held');
});
