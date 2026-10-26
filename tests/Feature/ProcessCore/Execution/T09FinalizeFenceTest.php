<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\ArtifactRef;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\MissingParts;
use App\Dto\Procurement\Process\ProviderRecoveryPlan;
use App\Dto\Procurement\Process\RetryAt;
use App\Dto\Procurement\Process\StageBlocked;
use App\Dto\Procurement\Process\StageDeferred;
use App\Dto\Procurement\Process\StageFailedItem;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\StagePartial;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\Process\WorkCheckpointEnvelope;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkIdentity;
use App\Enums\Procurement\Process\AccountingRole;
use App\Enums\Procurement\Process\BlockedReason;
use App\Enums\Procurement\Process\CheckpointStatus;
use App\Enums\Procurement\Process\ClaimStatus;
use App\Enums\Procurement\Process\CompletionStatus;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\DeferralReason;
use App\Enums\Procurement\Process\ItemFailureReason;
use App\Enums\Procurement\Process\ProviderOperationPurpose;
use App\Enums\Procurement\Process\ProviderRecoveryMode;
use App\Enums\Procurement\Process\RunWorkRelation;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\WorkState;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FixtureStageCheckpoint;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('t09', 't06', 't03', 'p18');

function finalizeRow(WorkId $work): object
{
    return DB::selectOne(
        'SELECT state, reason_code, generation, owner_token, lease_until, dispatch_claim_until, due_at, provider_recovery_due_at,
                checkpoint::text AS checkpoint, checkpoint_schema_version, checkpoint_seq, item_failures, transition_seq,
                extract(epoch FROM due_at - clock_timestamp()) AS due_in,
                extract(epoch FROM provider_recovery_due_at - clock_timestamp()) AS provider_due_in,
                crawl_block_id
         FROM prc_process_work WHERE id = ?',
        [$work->value->value],
    );
}

function finalizeBlock(WorkId $work): object
{
    return DB::selectOne(
        'SELECT cursor::text AS cursor, terminal_proof::text AS terminal_proof FROM prc_crawl_blocks
         WHERE id = (SELECT crawl_block_id FROM prc_process_work WHERE id = ?)',
        [$work->value->value],
    );
}

function finalizeLastTransition(WorkId $work): object
{
    return DB::table('prc_process_transitions')->where('work_id', $work->value->value)->orderByDesc('entity_seq')->first();
}

function finalizeAttempts(WorkId $work): array
{
    return DB::table('prc_process_attempts')->where('work_id', $work->value->value)->orderBy('generation')
        ->get(['generation', 'terminal_kind', 'finished_at', DB::raw("encode(fault_signature, 'hex') AS signature")])->all();
}

/** Process ledger и crawl block rows одного subject: snapshot для проверки «ноль записей». */
function finalizeLedger(): array
{
    return [
        DB::table('prc_process_work')->count(), DB::table('prc_process_transitions')->count(), DB::table('prc_process_outbox')->count(),
        DB::table('prc_process_reservations')->count(), DB::table('prc_process_run_work')->count(), DB::table('prc_process_incidents')->count(),
    ];
}

test('StageSucceeded атомарно: domain result, ready successors с run links, succeeded, attempt, release всей group, transition, projection и coalesced feeder wake', function (): void {
    $results = FinalizeFixtures::authority([StageCode::Listing])['listing'];
    $subject = LedgerFixtures::crawlBlock();
    $work = ExecutionFixtures::store(LedgerFixtures::listingInput($subject['block'], $subject['filter_hash'], $subject['access_policy_hash']), 'manual', 100);
    $other = ExecutionFixtures::listingWork();
    app(WorkStoreRepository::class)->linkRun($subject['run'], $work, RunWorkRelation::Created, AccountingRole::Required);
    $next = LedgerFixtures::crawlBlock();
    $successor = LedgerFixtures::listingInput($next['block'], $next['filter_hash'], $next['access_policy_hash']);
    $results->successors = [$successor];

    $lease = FinalizeFixtures::running($work);
    $result = app(WorkFinalizer::class)->finish($lease, FinalizeFixtures::succeeded(StageCode::Listing, 'a'));
    $row = finalizeRow($work);
    $group = DB::table('prc_admission_groups')->where('work_id', $work->value->value)->sole();
    $transition = finalizeLastTransition($work);
    $successorRow = DB::selectOne("SELECT id, state, cohort_key, due_at FROM prc_process_work WHERE work_key = decode(?, 'hex')", [(new WorkIdentity($successor))->key()->hex]);
    $wakes = DB::table('prc_process_outbox')->where('kind', 'feeder_wake')->get();

    expect([$result->status, $result->state])->toBe([CompletionStatus::Applied, WorkState::Succeeded])
        ->and([$row->state, $row->reason_code, $row->generation, $row->owner_token, $row->lease_until, $row->dispatch_claim_until, $row->due_at])
        ->toBe(['succeeded', null, 1, null, null, null, null])
        ->and([$row->checkpoint, $row->checkpoint_schema_version, $row->item_failures, $row->transition_seq])->toBe([null, null, 0, 4])
        ->and(array_map(fn (object $attempt): array => [$attempt->terminal_kind, $attempt->finished_at !== null, $attempt->signature], finalizeAttempts($work)))
        ->toBe([['succeeded', true, null]])
        ->and([$group->state, $group->release_reason, $group->version])->toBe(['released', 'succeeded', 3])
        ->and(DB::table('prc_process_reservations')->where('admission_group_id', $group->id)->count())->toBe(0)
        ->and(json_decode(finalizeBlock($work)->terminal_proof, true))->toBe(['artifact' => 'results/a'])
        ->and([$transition->previous_state, $transition->new_state, $transition->actor_kind, $transition->generation])->toBe(['running', 'succeeded', 'worker', 1])
        ->and(DB::table('prc_process_outbox')->where('dedup_key', 'project:'.$transition->event_id)->count())->toBe(1)
        ->and($wakes)->toHaveCount(1)
        ->and($wakes->first()->dedup_key)->toStartWith('feed:acquisition:')
        ->and(json_decode((string) $wakes->first()->payload, true))->toBe(['lane_key' => 'acquisition'])
        ->and([$successorRow->state, $successorRow->cohort_key, $successorRow->due_at])->toBe(['ready', 'manual', null])
        ->and(DB::table('prc_process_run_work')->where('work_id', $successorRow->id)->get(['run_id', 'relation', 'accounting_role'])->map(fn ($link): array => (array) $link)->all())
        ->toBe([['run_id' => $subject['run']->value, 'relation' => 'discovered', 'accounting_role' => 'required']]);

    // Повторный finish того же lease — не владелец; второй work с тем же successor не дублирует его.
    $before = finalizeLedger();
    expect(app(WorkFinalizer::class)->finish($lease, FinalizeFixtures::succeeded(StageCode::Listing, 'again'))->status)->toBe(CompletionStatus::LostOwnership)
        ->and(finalizeLedger())->toBe($before)
        ->and($results->resultWrites)->toBe(1);
    $otherLease = FinalizeFixtures::running($other);
    expect(app(WorkFinalizer::class)->finish($otherLease, FinalizeFixtures::succeeded(StageCode::Listing, 'b'))->state)->toBe(WorkState::Succeeded)
        ->and(DB::table('prc_process_work')->where('work_key', DB::raw("decode('".(new WorkIdentity($successor))->key()->hex."', 'hex')"))->count())->toBe(1)
        ->and(DB::table('prc_process_outbox')->where('kind', 'feeder_wake')->count())->toBeLessThanOrEqual(2);
});

test('D9: истёкший, но не отозванный owner коммитит готовый результат; heartbeat и checkpoint истёкшего owner отказаны', function (): void {
    $results = FinalizeFixtures::authority([StageCode::Listing])['listing'];
    $work = ExecutionFixtures::listingWork();
    $lease = FinalizeFixtures::running($work);
    $store = app(WorkStoreRepository::class);
    $renewed = $store->heartbeat($lease);
    FinalizeFixtures::expire($work);

    expect($renewed->isRenewed())->toBeTrue()
        ->and($store->heartbeat($lease)->isRenewed())->toBeFalse()
        ->and($store->checkpoint($lease, new FixtureStageCheckpoint(5), 0)->status)->toBe(CheckpointStatus::LostOwnership)
        ->and(finalizeBlock($work)->cursor)->toBe('{}')
        ->and($results->progressWrites)->toBe(0)
        ->and(finalizeRow($work)->checkpoint_seq)->toBe(0);

    $result = app(WorkFinalizer::class)->finish($lease, FinalizeFixtures::succeeded(StageCode::Listing, 'late'));
    expect([$result->status, $result->state])->toBe([CompletionStatus::Applied, WorkState::Succeeded])
        ->and(json_decode(finalizeBlock($work)->terminal_proof, true))->toBe(['artifact' => 'results/late'])
        ->and(finalizeRow($work)->generation)->toBe(1)
        // Revocation после принятого commit не переписывает terminal state.
        ->and(FinalizeFixtures::revoke($work))->toBeFalse()
        ->and(finalizeRow($work)->state)->toBe('succeeded');
});

test('generation revocation до finalize: LostOwnership без domain writes, successors, fault и повторного release', function (bool $cancel): void {
    $results = FinalizeFixtures::authority([StageCode::Listing])['listing'];
    $work = ExecutionFixtures::listingWork();
    $next = LedgerFixtures::crawlBlock();
    $results->successors = [LedgerFixtures::listingInput($next['block'], $next['filter_hash'], $next['access_policy_hash'])];
    $lease = FinalizeFixtures::running($work);
    if (! $cancel) {
        FinalizeFixtures::expire($work);
    }
    expect(FinalizeFixtures::revoke($work, $cancel))->toBeTrue();
    $before = finalizeLedger();
    $finalizer = app(WorkFinalizer::class);

    expect($finalizer->finish($lease, FinalizeFixtures::succeeded(StageCode::Listing, 'stale'))->status)->toBe(CompletionStatus::LostOwnership)
        ->and($finalizer->finishHandlerFault($lease, FinalizeFixtures::fault('stale'))->status)->toBe(CompletionStatus::LostOwnership)
        ->and($finalizer->finish($lease, new StagePartial(new FixtureStageCheckpoint(3), new MissingParts(['page:2'])))->status)->toBe(CompletionStatus::LostOwnership)
        ->and(app(WorkStoreRepository::class)->heartbeat($lease)->isRenewed())->toBeFalse()
        ->and(app(WorkStoreRepository::class)->checkpoint($lease, new FixtureStageCheckpoint(4), 0)->status)->toBe(CheckpointStatus::LostOwnership)
        ->and(finalizeLedger())->toBe($before)
        ->and([$results->resultWrites, $results->progressWrites])->toBe([0, 0])
        ->and([finalizeBlock($work)->cursor, finalizeBlock($work)->terminal_proof])->toBe(['{}', null])
        ->and([finalizeRow($work)->state, finalizeRow($work)->generation])->toBe([$cancel ? 'cancelled' : 'ready', 2])
        ->and(array_map(fn (object $attempt): array => [$attempt->terminal_kind, $attempt->signature], finalizeAttempts($work)))
        ->toBe([[$cancel ? 'cancelled' : 'interrupted', null]])
        ->and(DB::table('prc_admission_groups')->where('work_id', $work->value->value)->value('release_reason'))->toBe($cancel ? 'cancelled' : 'lease_recovery');
})->with([
    'recovery истёкшего lease' => [false],
    'hard cancel живого lease' => [true],
]);

test('новый subject head у того же owner: fenced superseded, старый result не публикуется, checkpoint InputSuperseded', function (): void {
    $results = FinalizeFixtures::authority([StageCode::Detail, StageCode::Listing]);
    $detail = ExecutionFixtures::detailWork();
    $listing = ExecutionFixtures::listingWork();
    $detailLease = FinalizeFixtures::running($detail);
    $listingLease = FinalizeFixtures::running($listing);
    DB::update('UPDATE prc_procurements SET head_generation = head_generation + 1 WHERE id = (SELECT procurement_id FROM prc_process_work WHERE id = ?)', [$detail->value->value]);
    DB::update('UPDATE prc_crawl_blocks SET input_revision = input_revision + 1, head_generation = head_generation + 1 WHERE id = (SELECT crawl_block_id FROM prc_process_work WHERE id = ?)', [$listing->value->value]);

    expect(app(WorkStoreRepository::class)->checkpoint($listingLease, new FixtureStageCheckpoint(2), 0)->status)->toBe(CheckpointStatus::InputSuperseded)
        ->and([finalizeBlock($listing)->cursor, finalizeRow($listing)->checkpoint_seq])->toBe(['{}', 0]);

    foreach ([[$detail, $detailLease, StageCode::Detail], [$listing, $listingLease, StageCode::Listing]] as [$work, $lease, $stage]) {
        $result = app(WorkFinalizer::class)->finish($lease, FinalizeFixtures::succeeded($stage, 'v1'));
        $row = finalizeRow($work);
        expect([$result->status, $result->state])->toBe([CompletionStatus::Applied, WorkState::Superseded])
            ->and([$row->state, $row->reason_code, $row->generation, $row->owner_token, $row->lease_until])->toBe(['superseded', 'input_invalidated', 2, null, null])
            ->and(array_column(finalizeAttempts($work), 'terminal_kind'))->toBe(['superseded'])
            ->and(DB::table('prc_admission_groups')->where('work_id', $work->value->value)->value('release_reason'))->toBe('superseded')
            ->and([finalizeLastTransition($work)->previous_state, finalizeLastTransition($work)->new_state])->toBe(['running', 'superseded']);
    }
    expect([$results['detail']->resultWrites, $results['listing']->resultWrites])->toBe([0, 0])
        ->and(finalizeBlock($listing)->terminal_proof)->toBeNull();
});

test('checkpoint: merged envelope, CAS seq и lease до domain progress; stale seq и сбой progress откатывают всё', function (): void {
    $results = FinalizeFixtures::authority([StageCode::Listing])['listing'];
    $work = ExecutionFixtures::listingWork();
    $lease = FinalizeFixtures::running($work);
    $store = app(WorkStoreRepository::class);
    $leaseBefore = finalizeRow($work)->lease_until;

    $saved = $store->checkpoint($lease, new FixtureStageCheckpoint(3), 0);
    $row = finalizeRow($work);
    expect([$saved->status, $saved->checkpointSeq])->toBe([CheckpointStatus::Saved, 1])
        ->and($saved->checkpoint?->stageProgress)->toEqual(new FixtureStageCheckpoint(3))
        ->and([$row->checkpoint_seq, $row->state, $row->generation])->toBe([1, 'running', 1])
        ->and($row->lease_until >= $leaseBefore)->toBeTrue()
        ->and(json_decode(finalizeBlock($work)->cursor, true))->toBe(['position' => 3])
        ->and(DB::table('prc_process_attempts')->where('work_id', $work->value->value)->value('last_checkpoint_at'))->not->toBeNull();

    expect($store->checkpoint($lease, new FixtureStageCheckpoint(4), 0)->status)->toBe(CheckpointStatus::StaleCheckpoint)
        ->and(fn () => $store->checkpoint($lease, new FixtureStageCheckpoint(4, StageCode::Detail), 1))->toThrow(InvalidArgumentException::class);
    $results->failProgress = true;
    expect(fn () => $store->checkpoint($lease, new FixtureStageCheckpoint(9), 1))->toThrow(RuntimeException::class, 'Fixture progress write failed.');
    $results->failProgress = false;
    expect([finalizeRow($work)->checkpoint_seq, json_decode(finalizeBlock($work)->cursor, true)])->toBe([1, ['position' => 3]]);

    $next = $store->checkpoint($lease, new FixtureStageCheckpoint(6), 1);
    expect([$next->status, $next->checkpointSeq, json_decode(finalizeBlock($work)->cursor, true)])->toBe([CheckpointStatus::Saved, 2, ['position' => 6]]);
});

test('пять closed outcomes: partial ready с merged recovery plan, deferred не раньше RetryAt с jitter, blocked без due, provider unknown только с pinned plan', function (): void {
    FinalizeFixtures::authority([StageCode::Listing], lanes: [
        'acquisition.listing' => ['max_outstanding' => 10, 'max_running' => 5, 'low_watermark' => 9, 'refill_max' => 5],
        'acquisition' => ['max_running' => 5, 'low_watermark' => 19],
    ]);
    [$partial, $plain, $deferred, $past] = [ExecutionFixtures::listingWork(), ExecutionFixtures::listingWork(), ExecutionFixtures::listingWork(), ExecutionFixtures::listingWork()];
    $codec = app(StageRegistry::class)->payloadCodec();
    $inputHash = (string) DB::selectOne("SELECT encode(input_hash, 'hex') AS hash FROM prc_process_work WHERE id = ?", [$partial->value->value])->hash;
    $plan = new ProviderRecoveryPlan(ProviderRecoveryMode::BlockedUnknown, LedgerFixtures::uuid(), LedgerFixtures::uuid(), ProviderOperationPurpose::Generation, null, new ContentHash($inputHash), LedgerFixtures::hash('recipe'), 1);
    // Recovery plan ранее записал recovery (P1.11): checkpoint модели работы до claim.
    $seeded = $codec->encodeCheckpoint(new WorkCheckpointEnvelope(new FixtureStageCheckpoint(1), $plan));
    DB::update('UPDATE prc_process_work SET checkpoint = ?::jsonb, checkpoint_schema_version = 1, checkpoint_seq = 1 WHERE id = ?', [$seeded->json, $partial->value->value]);
    $finalizer = app(WorkFinalizer::class);

    $lease = FinalizeFixtures::running($partial);
    expect($lease->checkpoint?->providerRecoveryPlan)->toEqual($plan)
        ->and($finalizer->finish($lease, new StagePartial(new FixtureStageCheckpoint(7), new MissingParts(['page:2'])))->state)->toBe(WorkState::Ready);
    $row = finalizeRow($partial);
    $envelope = $codec->decodeCheckpoint(new VersionedPayload($row->checkpoint, $row->checkpoint_schema_version));
    expect([$row->state, $row->due_at, $row->reason_code, $row->checkpoint_seq, $row->generation])->toBe(['ready', null, null, 2, 1])
        ->and($envelope?->stageProgress)->toEqual(new FixtureStageCheckpoint(7))
        ->and($envelope?->providerRecoveryPlan)->toEqual($plan)
        ->and(json_decode(finalizeBlock($partial)->cursor, true))->toBe(['position' => 7])
        ->and(array_column(finalizeAttempts($partial), 'terminal_kind'))->toBe(['partial']);

    // Новый claim получает merged envelope; blocked(provider_result_unknown) допустим только с pinned plan.
    $again = FinalizeFixtures::running($partial);
    expect($again->checkpoint?->stageProgress)->toEqual(new FixtureStageCheckpoint(7))
        ->and($finalizer->finish($again, new StageBlocked(BlockedReason::ProviderResultUnknown))->state)->toBe(WorkState::Blocked);
    $row = finalizeRow($partial);
    expect([$row->state, $row->reason_code, $row->due_at])->toBe(['blocked', 'provider_result_unknown', null])
        ->and((float) $row->provider_due_in)->toBeGreaterThan(28.0)->toBeLessThanOrEqual(30.0)
        ->and($row->checkpoint)->not->toBeNull();

    $plainLease = FinalizeFixtures::running($plain);
    $before = finalizeLedger();
    expect($finalizer->finish($plainLease, new StageBlocked(BlockedReason::ProviderResultUnknown))->status)->toBe(CompletionStatus::InvariantViolation)
        ->and(finalizeLedger())->toBe($before)
        ->and(finalizeRow($plain)->state)->toBe('running')
        ->and($finalizer->finish($plainLease, new StageBlocked(BlockedReason::Paused))->state)->toBe(WorkState::Blocked)
        ->and([finalizeRow($plain)->reason_code, finalizeRow($plain)->due_at, finalizeRow($plain)->provider_recovery_due_at])->toBe(['paused', null, null]);

    $now = app(ProcessClock::class)->dbNow()->value;
    $deferredLease = FinalizeFixtures::running($deferred);
    $pastLease = FinalizeFixtures::running($past);
    $finalizer->finish($deferredLease, new StageDeferred(DeferralReason::RateLimit, new RetryAt($now->modify('+100 seconds'))));
    $finalizer->finish($pastLease, new StageDeferred(DeferralReason::NoRoute, new RetryAt($now->modify('-30 seconds'))));
    $row = finalizeRow($deferred);
    expect([$row->state, $row->reason_code, $row->item_failures])->toBe(['deferred', 'rate_limit', 0])
        ->and((float) $row->due_in)->toBeGreaterThan(98.0)->toBeLessThanOrEqual(110.0)
        ->and(finalizeRow($past)->reason_code)->toBe('no_route')
        ->and((float) finalizeRow($past)->due_in)->toBeGreaterThan(-2.0)->toBeLessThanOrEqual(0.0)
        ->and(DB::table('prc_process_reservations')->count())->toBe(0);
});

test('T03 item budget: три StageFailedItem одного work_key → deferred +30 s, +120 s, затем failed_item; четвёртая delivery без attempt', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();
    $finalizer = app(WorkFinalizer::class);
    $rejected = new StageFailedItem(ItemFailureReason::ContractRejected, new ArtifactRef('fixture', 'evidence/reject', LedgerFixtures::hash('reject')));

    $lease = FinalizeFixtures::running($work);
    $finalizer->finish($lease, new StagePartial(new FixtureStageCheckpoint(2), new MissingParts(['page:3'])));
    $observed = [];
    foreach ([1, 2, 3] as $attempt) {
        $lease = $attempt === 1 ? FinalizeFixtures::running($work) : FinalizeFixtures::rerun($work);
        $result = $finalizer->finish($lease, $rejected);
        $row = finalizeRow($work);
        $observed[] = [$result->state, $row->reason_code, $row->item_failures, $row->due_in === null ? null : (int) round((float) $row->due_in)];
    }
    $row = finalizeRow($work);

    expect($observed)->toBe([
        [WorkState::Deferred, 'item_retry', 1, 30],
        [WorkState::Deferred, 'item_retry', 2, 120],
        [WorkState::FailedItem, 'contract_rejected', 3, null],
    ])
        ->and([$row->checkpoint, $row->checkpoint_schema_version, $row->due_at])->toBe([null, null, null])
        ->and(app(WorkStoreRepository::class)->claim($work, ExecutionFixtures::delivery($work))->status)->toBe(ClaimStatus::WorkTerminal)
        ->and(DB::table('prc_process_attempts')->where('work_id', $work->value->value)->count())->toBe(4)
        ->and(DB::table('prc_process_attempts')->whereNotNull('fault_signature')->count())->toBe(0);
});

test('finalize берёт full prefix control SHARE → handler SHARE → capacity → subject → work без UPDATE control/handler rows; pause и hold не отбрасывают результат', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();
    $lease = FinalizeFixtures::running($work);
    ExecutionFixtures::setMode('acquisition', ControlMode::Paused);
    ExecutionFixtures::holdHandler(StageCode::Listing);
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $result = app(WorkFinalizer::class)->finish($lease, FinalizeFixtures::succeeded(StageCode::Listing, 'paused'));
    $tiers = [];
    foreach ($statements as $sql) {
        if (preg_match('/FOR (SHARE|UPDATE)/', $sql, $mode) !== 1) {
            continue;
        }
        $tiers[] = match (true) {
            str_contains($sql, 'FROM prc_control_scopes') => [1, $mode[1]],
            str_contains($sql, 'FROM prc_process_handler_holds') => [2, $mode[1]],
            str_contains($sql, 'FROM prc_process_lanes') => [3, $mode[1]],
            (bool) preg_match('/FROM (prc_crawl_blocks|prc_procurements|prc_documents) /', $sql) => [4, $mode[1]],
            str_contains($sql, 'FROM prc_process_work') => [5, $mode[1]],
            default => [99, $mode[1]],
        };
    }
    $order = array_column($tiers, 0);
    $sorted = $order;
    sort($sorted);

    expect($result->state)->toBe(WorkState::Succeeded)
        ->and($order)->toBe($sorted)
        ->and(array_values(array_unique($order)))->toBe([1, 2, 3, 4, 5])
        ->and(array_unique(array_column(array_filter($tiers, fn (array $tier): bool => $tier[0] <= 2), 1)))->toBe(['SHARE'])
        ->and(array_unique(array_column(array_filter($tiers, fn (array $tier): bool => $tier[0] >= 3), 1)))->toBe(['UPDATE'])
        ->and(array_filter($statements, fn (string $sql): bool => preg_match('/UPDATE\s+prc_(control_scopes|process_handler_holds)/', $sql) === 1))->toBe([])
        ->and(DB::table('prc_control_scopes')->where('scope_key', 'acquisition')->value('desired_mode'))->toBe('paused');
});

test('нарушение exact membership при finalize: InvariantViolation без domain writes и incident без молчаливого repair', function (): void {
    $results = FinalizeFixtures::authority([StageCode::Listing])['listing'];
    $work = ExecutionFixtures::listingWork();
    $lease = FinalizeFixtures::running($work);
    $group = DB::table('prc_admission_groups')->where('work_id', $work->value->value)->sole();
    $child = DB::table('prc_process_lanes')->where('lane_key', 'acquisition.listing')->value('id');
    DB::delete('DELETE FROM prc_process_reservations WHERE admission_group_id = ? AND lane_id = ?', [$group->id, $child]);

    $result = app(WorkFinalizer::class)->finish($lease, FinalizeFixtures::succeeded(StageCode::Listing, 'corrupt'));
    $incident = DB::table('prc_process_incidents')->sole();

    expect($result->status)->toBe(CompletionStatus::InvariantViolation)
        ->and([$incident->kind, $incident->state, $incident->scope_key])->toBe(['admission_membership_mismatch', 'open', 'work:'.$work->value->value])
        ->and(DB::table('prc_process_transitions')->where('incident_id', $incident->id)->value('new_state'))->toBe('open')
        ->and($results->resultWrites)->toBe(0)
        ->and(finalizeBlock($work)->terminal_proof)->toBeNull()
        ->and([finalizeRow($work)->state, finalizeRow($work)->generation])->toBe(['running', 1])
        ->and(DB::table('prc_admission_groups')->where('id', $group->id)->value('state'))->toBe('running')
        ->and(DB::table('prc_process_reservations')->where('admission_group_id', $group->id)->count())->toBe(1);
});

test('finish исчерпывающе: неизвестный StageOutcome — LogicException без записей', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();
    $lease = FinalizeFixtures::running($work);
    $before = finalizeLedger();

    expect(fn () => app(WorkFinalizer::class)->finish($lease, new class implements StageOutcome {}))->toThrow(LogicException::class, 'Unknown StageOutcome variant.')
        ->and(finalizeLedger())->toBe($before)
        ->and(finalizeRow($work)->state)->toBe('running');
});
