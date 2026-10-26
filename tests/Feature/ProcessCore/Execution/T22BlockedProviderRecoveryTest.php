<?php

declare(strict_types=1);

use App\Actions\Procurement\Process\CancelWork;
use App\Dto\Procurement\Process\ProviderRecoveryPlan;
use App\Dto\Procurement\Process\WorkCheckpointEnvelope;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\CompletionStatus;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\ProviderOperationPurpose;
use App\Enums\Procurement\Process\ProviderRecoveryMode;
use App\Enums\Procurement\Process\StageCode;
use App\Services\Procurement\Process\NoProviderOperations;
use App\Services\Procurement\Process\ReconcileDaemon;
use App\Services\Procurement\Process\RecoveryReconciler;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FixtureProviderRecoveryResolver;
use Tests\Support\ProcessCore\FixtureStageCheckpoint;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('t22', 't15', 't09', 'p111');

// EXR-03 на реальном RecoveryReconciler и PG: late receipt подбирается periodic blocked scan без новой
// queue delivery и без прямого вызова resolver из теста. Fixture resolver — только факты и счётчики
// settlement; реальные paid stages не активны.

function providerRow(WorkId $work): object
{
    return DB::selectOne(
        'SELECT state, reason_code, generation, due_at, transition_seq, checkpoint_seq, checkpoint::text AS checkpoint,
                extract(epoch FROM provider_recovery_due_at - clock_timestamp()) AS recovery_in
         FROM prc_process_work WHERE id = ?',
        [$work->value->value],
    );
}

/** @return array<string, mixed>|null */
function providerPlanOf(WorkId $work): ?array
{
    $checkpoint = providerRow($work)->checkpoint;

    return $checkpoint === null ? null : json_decode($checkpoint, true)['provider_recovery_plan'];
}

/** Late receipt: reconciliation due наступил (ход времени), без receipt-notification. */
function providerDue(WorkId $work): void
{
    DB::update("UPDATE prc_process_work SET provider_recovery_due_at = clock_timestamp() - interval '1 second' WHERE id = ?", [$work->value->value]);
}

/**
 * Running work → expired → настоящий recovery с finding BlockedUnknown: committed blocked
 * (provider_result_unknown) с pinned plan, группой released и reconciliation due +30 s.
 *
 * @return array{WorkId, ProviderRecoveryPlan}
 */
function providerBlocked(FixtureProviderRecoveryResolver $resolver, ProviderOperationPurpose $purpose = ProviderOperationPurpose::Generation): array
{
    $work = ExecutionFixtures::listingWork();
    FinalizeFixtures::running($work);
    $unknown = FixtureProviderRecoveryResolver::plan(ProviderRecoveryMode::BlockedUnknown, FinalizeFixtures::inputHash($work), purpose: $purpose);
    $resolver->finding($work, $unknown);
    FinalizeFixtures::expire($work, 600);
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1);

    return [$work, $unknown];
}

test('EXR-03 expired running с claimed send без receipt → blocked(provider_result_unknown): pinned plan, due_at NULL, reconciliation +30 s, group released, reserve не settled', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $resolver = FixtureProviderRecoveryResolver::install();
    [$work, $unknown] = providerBlocked($resolver);

    $row = providerRow($work);
    $plan = providerPlanOf($work);
    expect([$row->state, $row->reason_code, $row->due_at])->toBe(['blocked', 'provider_result_unknown', null])
        ->and((float) $row->recovery_in)->toBeGreaterThan(20.0)->toBeLessThanOrEqual(30.0)
        ->and([$plan['mode'], $plan['logical_request_id'], $plan['physical_call_id'], $plan['receipt']])
        ->toBe(['blocked_unknown', $unknown->logicalRequestId->value, $unknown->physicalCallId->value, null])
        // Работа без устойчивого прогресса несёт initial progress codec, а не пустой envelope.
        ->and(json_decode($row->checkpoint, true)['stage_progress']['payload'])->toBe(['position' => 0])
        ->and(DB::table('prc_process_reservations')->count())->toBe(0)
        ->and($resolver->settlements)->toBe([]);
});

test('EXR-03 late receipt: настоящий periodic scan возвращает ТОТ ЖЕ work в ready/resume без queue delivery; settlement 1, новых send/reserve 0, публикация 1', function (ProviderRecoveryMode $mode, ProviderOperationPurpose $purpose, string $planMode): void {
    $results = FinalizeFixtures::authority([StageCode::Listing])['listing'];
    $resolver = FixtureProviderRecoveryResolver::install();
    [$work, $unknown] = providerBlocked($resolver, $purpose);
    $wakes = DB::table('prc_process_outbox')->where('kind', 'work_wake')->count();
    $attempts = DB::table('prc_process_attempts')->count();

    // До receipt scan не due: elapsed time сам ничего не снимает.
    expect(app(RecoveryReconciler::class)->recoverBlockedProvider())->toBe(0)
        ->and($resolver->inspections)->toBe(1);

    $resolver->finding($work, FixtureProviderRecoveryResolver::resolved($unknown, $mode, $purpose));
    providerDue($work);
    $before = providerRow($work);

    $daemon = app(ReconcileDaemon::class);
    foreach (app(RecoveryReconciler::class)->categories() as $category) {
        $daemon->register($category);
    }
    $tick = $daemon->tick();
    expect([$tick->processed, $tick->errorCode])->toBe([1, null]);

    $row = providerRow($work);
    $plan = providerPlanOf($work);
    expect([$row->state, $row->reason_code, $row->recovery_in])->toBe(['ready', null, null])
        ->and($row->transition_seq)->toBe($before->transition_seq + 1)
        ->and($row->checkpoint_seq)->toBe($before->checkpoint_seq + 1)
        ->and($plan['mode'])->toBe($planMode)
        ->and([$plan['logical_request_id'], $plan['physical_call_id']])->toBe([$unknown->logicalRequestId->value, $unknown->physicalCallId->value])
        ->and(($plan['receipt'] !== null))->toBe($mode !== ProviderRecoveryMode::KnownRetryEligible)
        ->and($resolver->settlements)->toBe([$unknown->physicalCallId->value.':'.$planMode])
        ->and(DB::table('prc_process_outbox')->where('kind', 'work_wake')->count())->toBe($wakes)
        ->and(DB::table('prc_process_attempts')->count())->toBe($attempts)
        ->and(DB::table('prc_process_reservations')->count())->toBe(0);

    // Два reconciler подряд: второй — no-op, settlement остаётся единственным.
    expect(app(RecoveryReconciler::class)->recoverBlockedProvider())->toBe(0)
        ->and($resolver->settlements)->toHaveCount(1);

    // Дальше обычный admission → claim несёт pinned plan в lease; ровно одна публикация.
    $lease = FinalizeFixtures::running($work);
    expect($lease->checkpoint?->providerRecoveryPlan)->toBeInstanceOf(ProviderRecoveryPlan::class)
        ->and($lease->checkpoint->providerRecoveryPlan->mode->value)->toBe($planMode)
        ->and(app(WorkFinalizer::class)->finish($lease, FinalizeFixtures::succeeded(StageCode::Listing, 'resumed'))->status)->toBe(CompletionStatus::Applied)
        ->and($results->resultWrites)->toBe(1)
        ->and($resolver->settlements)->toHaveCount(1);
})->with([
    'verified successful generation → resume_from_receipt' => [ProviderRecoveryMode::ResumeFromReceipt, ProviderOperationPurpose::Generation, 'resume_from_receipt'],
    'cache/file/lookup success → resume_provider_asset' => [ProviderRecoveryMode::ResumeProviderAsset, ProviderOperationPurpose::Cache, 'resume_provider_asset'],
    'file success → resume_provider_asset' => [ProviderRecoveryMode::ResumeProviderAsset, ProviderOperationPurpose::File, 'resume_provider_asset'],
    'lookup success → resume_provider_asset' => [ProviderRecoveryMode::ResumeProviderAsset, ProviderOperationPurpose::Lookup, 'resume_provider_asset'],
    'known nonbillable cache miss → known_retry_eligible' => [ProviderRecoveryMode::KnownRetryEligible, ProviderOperationPurpose::Generation, 'known_retry_eligible'],
]);

test('EXR-03 verified error с неподтверждённой оплатой, отсутствие receipt и недоступное хранилище: unknown и reserve сохранены, меняется только reconciliation due', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $resolver = FixtureProviderRecoveryResolver::install();
    [$work, $unknown] = providerBlocked($resolver);

    // (b) ответ-ошибка без доказательства бесплатности → blocked_unknown finding.
    providerDue($work);
    $before = providerRow($work);
    expect(app(RecoveryReconciler::class)->recoverBlockedProvider())->toBe(0);
    $after = providerRow($work);
    expect([$after->state, $after->reason_code, $after->transition_seq, $after->checkpoint_seq, $after->checkpoint])
        ->toBe([$before->state, $before->reason_code, $before->transition_seq, $before->checkpoint_seq, $before->checkpoint])
        ->and((float) $after->recovery_in)->toBeGreaterThan(20.0)
        ->and($resolver->settlements)->toBe([]);

    // Хранилище receipt недоступно: dependency wait, не разрешение send.
    $resolver->finding($work, FixtureProviderRecoveryResolver::resolved($unknown, ProviderRecoveryMode::ResumeFromReceipt));
    $resolver->storageDown = true;
    providerDue($work);
    expect(app(RecoveryReconciler::class)->recoverBlockedProvider())->toBe(0);
    $down = providerRow($work);
    expect([$down->state, $down->reason_code, $down->transition_seq])->toBe(['blocked', 'provider_result_unknown', $before->transition_seq])
        ->and((float) $down->recovery_in)->toBeGreaterThan(20.0)
        ->and($resolver->settlements)->toBe([]);

    // Отсутствующий pinned linkage — incident, а не поиск «последнего call».
    $resolver->storageDown = false;
    DB::update(
        "UPDATE prc_process_work SET checkpoint = jsonb_set(checkpoint, '{provider_recovery_plan}', '{\"variant\":\"none\"}'::jsonb) WHERE id = ?",
        [$work->value->value],
    );
    providerDue($work);
    expect(app(RecoveryReconciler::class)->recoverBlockedProvider())->toBe(0)
        ->and(DB::table('prc_process_incidents')->where('kind', 'provider_unknown_linkage_missing')->where('state', 'open')->count())->toBe(1)
        ->and(providerRow($work)->state)->toBe('blocked')
        ->and($resolver->settlements)->toBe([]);
});

test('EXR-03 receipt другой operation, purpose, input, recipe или request epoch не меняет pinned unknown и не settled', function (string $dimension): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $resolver = FixtureProviderRecoveryResolver::install();
    [$work, $unknown] = providerBlocked($resolver);
    $valid = FixtureProviderRecoveryResolver::resolved($unknown, ProviderRecoveryMode::ResumeFromReceipt);
    $resolver->finding($work, new ProviderRecoveryPlan(
        mode: $dimension === 'purpose' ? ProviderRecoveryMode::ResumeProviderAsset : $valid->mode,
        logicalRequestId: $dimension === 'logical' ? LedgerFixtures::uuid() : $valid->logicalRequestId,
        physicalCallId: $dimension === 'physical' ? LedgerFixtures::uuid() : $valid->physicalCallId,
        purpose: $dimension === 'purpose' ? ProviderOperationPurpose::File : $valid->purpose,
        receipt: $valid->receipt,
        expectedInputHash: $dimension === 'input' ? LedgerFixtures::hash('other-input') : $valid->expectedInputHash,
        recipeHash: $dimension === 'recipe' ? LedgerFixtures::hash('other-recipe') : $valid->recipeHash,
        requestEpoch: $valid->requestEpoch + (int) ($dimension === 'epoch'),
    ));
    providerDue($work);
    $before = providerRow($work);

    expect(app(RecoveryReconciler::class)->recoverBlockedProvider())->toBe(0);
    $after = providerRow($work);
    expect([$after->state, $after->checkpoint, $after->checkpoint_seq, $after->transition_seq])
        ->toBe(['blocked', $before->checkpoint, $before->checkpoint_seq, $before->transition_seq])
        ->and((float) $after->recovery_in)->toBeGreaterThan(20.0)
        ->and($resolver->settlements)->toBe([])
        ->and(DB::table('prc_process_reservations')->count())->toBe(0);
})->with(['logical', 'physical', 'purpose', 'input', 'recipe', 'epoch']);

test('T22 restart без provider resolver не разрешает повторный send работы с durable recovery plan', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $resolver = FixtureProviderRecoveryResolver::install();
    [$work, $unknown] = providerBlocked($resolver);
    $resolver->finding($work, FixtureProviderRecoveryResolver::resolved($unknown, ProviderRecoveryMode::ResumeFromReceipt));
    providerDue($work);
    expect(app(RecoveryReconciler::class)->recoverBlockedProvider())->toBe(1);
    $lease = FinalizeFixtures::running($work);
    $pinned = providerRow($work)->checkpoint;
    FinalizeFixtures::expire($work);
    app(RecoveryReconciler::class)->bindProviderResolver(new NoProviderOperations);

    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1);
    $row = providerRow($work);
    expect([$row->state, $row->reason_code, $row->checkpoint])->toBe(['blocked', 'policy_review', $pinned])
        ->and($row->generation)->toBe($lease->generation + 1)
        ->and(DB::table('prc_process_reservations')->count())->toBe(0)
        ->and($resolver->settlements)->toHaveCount(1);
});

test('EXR-03 cancel и смена input после pre-read: settlement финансовой истории сохраняется, work не возобновляется и не публикуется', function (string $race): void {
    $results = FinalizeFixtures::authority([StageCode::Listing])['listing'];
    $resolver = FixtureProviderRecoveryResolver::install();
    [$work, $unknown] = providerBlocked($resolver);
    $resolver->finding($work, FixtureProviderRecoveryResolver::resolved($unknown, ProviderRecoveryMode::ResumeFromReceipt));
    providerDue($work);
    $resolver->afterInspect = static function (WorkId $id) use ($race): void {
        if ($race === 'cancel') {
            app(CancelWork::class)->execute($id, 'operator cancel after receipt');
        } else {
            DB::update('UPDATE prc_crawl_blocks SET input_revision = input_revision + 1, head_generation = head_generation + 1 WHERE id = (SELECT crawl_block_id FROM prc_process_work WHERE id = ?)', [$id->value->value]);
        }
    };

    expect(app(RecoveryReconciler::class)->recoverBlockedProvider())->toBe(1);

    $row = providerRow($work);
    expect($row->state)->toBe($race === 'cancel' ? 'cancelled' : 'superseded')
        ->and($row->checkpoint)->toBeNull()
        ->and($resolver->settlements)->toBe([$unknown->physicalCallId->value.':resume_from_receipt'])
        ->and($results->resultWrites)->toBe(0)
        ->and(DB::table('prc_process_reservations')->count())->toBe(0)
        ->and(app(RecoveryReconciler::class)->recoverBlockedProvider())->toBe(0);
})->with(['cancel', 'input drift']);

test('EXR-03 pause при verified receipt: blocked(paused) с сохранённым plan; resume освобождает work к тому же resume', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $resolver = FixtureProviderRecoveryResolver::install();
    [$work, $unknown] = providerBlocked($resolver);
    $resolver->finding($work, FixtureProviderRecoveryResolver::resolved($unknown, ProviderRecoveryMode::ResumeFromReceipt));
    providerDue($work);
    ExecutionFixtures::setMode('acquisition', ControlMode::Paused);

    expect(app(RecoveryReconciler::class)->recoverBlockedProvider())->toBe(1);
    $row = providerRow($work);
    expect([$row->state, $row->reason_code, $row->recovery_in])->toBe(['blocked', 'paused', null])
        ->and(providerPlanOf($work)['mode'])->toBe('resume_from_receipt')
        ->and($resolver->settlements)->toHaveCount(1);

    ExecutionFixtures::setMode('acquisition', ControlMode::Enabled);
    expect(app(RecoveryReconciler::class)->releaseBlocked())->toBe(1);
    $released = providerRow($work);
    expect([$released->state, $released->reason_code])->toBe(['ready', null])
        ->and(providerPlanOf($work)['mode'])->toBe('resume_from_receipt')
        ->and($resolver->settlements)->toHaveCount(1);
});

test('EXR-03 больше 50 кандидатов: batch ограничен, earliest-due обслуживается первым, остаток в следующем tick', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $resolver = FixtureProviderRecoveryResolver::install();
    ExecutionFixtures::ensureWorkScopes(StageCode::Listing, 'manual');
    $registry = app(StageRegistry::class);
    $works = [];
    foreach (range(1, 55) as $index) {
        $work = ExecutionFixtures::listingWork();
        $plan = FixtureProviderRecoveryResolver::plan(ProviderRecoveryMode::BlockedUnknown, FinalizeFixtures::inputHash($work));
        $payload = $registry->payloadCodec()->encodeCheckpoint(new WorkCheckpointEnvelope(new FixtureStageCheckpoint(0), $plan));
        // Факт: unknown-блокировка более раннего recovery; чем больше index, тем свежее due (index 55 — самый поздний).
        DB::update(
            "UPDATE prc_process_work SET state = 'blocked', reason_code = 'provider_result_unknown',
                    provider_recovery_due_at = clock_timestamp() - make_interval(secs => ?),
                    checkpoint = ?::jsonb, checkpoint_schema_version = ?, checkpoint_seq = 1, transition_seq = transition_seq + 1
             WHERE id = ?",
            [1000 - $index, $payload->json, $payload->schemaVersion, $work->value->value],
        );
        $works[$index] = $work;
    }

    expect(app(RecoveryReconciler::class)->recoverBlockedProvider())->toBe(0)
        ->and($resolver->inspections)->toBe(RecoveryReconciler::BATCH);
    // Первые 50 по due отложены на +30 s; пять самых поздних остались due.
    $due = DB::table('prc_process_work')->where('state', 'blocked')->whereRaw('provider_recovery_due_at <= clock_timestamp()')->pluck('id')->all();
    expect($due)->toEqualCanonicalizing(array_map(static fn (WorkId $id): string => $id->value->value, array_slice($works, 50, 5)));

    expect(app(RecoveryReconciler::class)->recoverBlockedProvider())->toBe(0)
        ->and($resolver->inspections)->toBe(55)
        ->and(DB::table('prc_process_work')->where('state', 'blocked')->whereRaw('provider_recovery_due_at <= clock_timestamp()')->count())->toBe(0);
});

test('T22 expired running provider-linked: not-sent → ready/known_retry, verified generation → resume plan, claimed без receipt → blocked; storage down оставляет running', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $resolver = FixtureProviderRecoveryResolver::install();

    // Состояние фиксируется сразу после recovery: следующий feeder tick уже может admit-ить ready work.
    $recover = function (ProviderRecoveryMode $mode) use ($resolver): array {
        $work = ExecutionFixtures::listingWork();
        FinalizeFixtures::running($work);
        $resolver->finding($work, FixtureProviderRecoveryResolver::plan($mode, FinalizeFixtures::inputHash($work)));
        FinalizeFixtures::expire($work, 600);
        expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1);

        return [providerRow($work)->state, providerRow($work)->reason_code, providerPlanOf($work)['mode'] ?? null];
    };

    expect($recover(ProviderRecoveryMode::KnownRetryEligible))->toBe(['ready', null, 'known_retry_eligible'])
        ->and($recover(ProviderRecoveryMode::ResumeFromReceipt))->toBe(['ready', null, 'resume_from_receipt'])
        ->and($recover(ProviderRecoveryMode::BlockedUnknown))->toBe(['blocked', 'provider_result_unknown', 'blocked_unknown'])
        // Settlement только у доказанных исходов; unknown сохраняет reserve.
        ->and($resolver->settlements)->toHaveCount(2);

    // Недоступное хранилище receipt: dependency wait без нового send, work остаётся running-expired.
    $down = ExecutionFixtures::listingWork();
    FinalizeFixtures::running($down);
    FinalizeFixtures::expire($down, 600);
    $resolver->storageDown = true;
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(0)
        ->and([providerRow($down)->state, providerRow($down)->generation])->toBe(['running', 1]);
});
