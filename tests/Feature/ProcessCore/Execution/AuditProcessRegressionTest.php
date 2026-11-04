<?php

declare(strict_types=1);

use App\Actions\Procurement\Process\CancelWork;
use App\Dto\Procurement\Process\ArtifactRef;
use App\Dto\Procurement\Process\LockedSubject;
use App\Dto\Procurement\Process\StageFailedItem;
use App\Dto\Procurement\Process\SubjectKey;
use App\Enums\Procurement\Process\CompletionStatus;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\ItemFailureReason;
use App\Enums\Procurement\Process\ProviderRecoveryMode;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Enums\Procurement\Process\WorkMutationStatus;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\Procurement\Platform\Platform;
use App\Models\User;
use App\Repositories\Procurement\Process\AuthorityRepository;
use App\Repositories\Procurement\Process\IntentRepository;
use App\Repositories\Procurement\Process\RunReadRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Process\FeederDaemon;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\SubjectLockResolver;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FixtureProviderRecoveryResolver;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('work-controls-and-fences', 'claim-recovery-and-invariants', 'delivery-and-daemon-lifecycle');

test('CancelWork очищает checkpoint для running работы с уже разрешённым provider plan, но сохраняет его для blocked(provider_result_unknown)', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $resolver = FixtureProviderRecoveryResolver::install();

    $runningWork = ExecutionFixtures::listingWork();
    FinalizeFixtures::running($runningWork);
    $plan = FixtureProviderRecoveryResolver::plan(
        ProviderRecoveryMode::KnownRetryEligible,
        FinalizeFixtures::inputHash($runningWork),
    );
    $resolver->finding($runningWork, $plan);

    expect(app(CancelWork::class)->execute($runningWork, 'cancel resolved running')->status)
        ->toBe(WorkMutationStatus::Applied)
        ->and(DB::table('prc_process_work')->where('id', $runningWork->value->value)->value('checkpoint'))
        ->toBeNull();
});

test('IntentRepository::activateNextLocked пропускает pending intent без work и вычисляет head_generation для prc_document_versions', function (): void {
    FinalizeFixtures::authority([StageCode::Download]);
    $document = Document::factory()->create();
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);

    $orphanIntent = LedgerFixtures::uuid();
    $validIntent = LedgerFixtures::uuid();

    app(ProcessTransaction::class)->run(function () use ($version, $orphanIntent, $validIntent): void {
        $store = app(WorkStoreRepository::class);
        $subject = new SubjectKey(SubjectKind::DocumentVersion, $version->id);
        $store->storeIntent(
            id: $orphanIntent,
            intentKey: LedgerFixtures::hash('orphan:'.$orphanIntent->value),
            stage: StageCode::Screening,
            subject: $subject,
            semanticPolicyHash: LedgerFixtures::hash('sem'),
            accessPolicyHash: LedgerFixtures::hash('acc'),
            cycleSeq: 1,
            expectedHeadGeneration: 1,
            state: IntentState::Pending,
        );
        $store->storeIntent(
            id: $validIntent,
            intentKey: LedgerFixtures::hash('valid:'.$validIntent->value),
            stage: StageCode::Screening,
            subject: $subject,
            semanticPolicyHash: LedgerFixtures::hash('sem2'),
            accessPolicyHash: LedgerFixtures::hash('acc2'),
            cycleSeq: 2,
            expectedHeadGeneration: 1,
            state: IntentState::Pending,
        );
    });

    DB::table('prc_operation_intents')->where('id', $orphanIntent->value)->update([
        'requested_at' => DB::raw("clock_timestamp() - interval '10 seconds'"),
    ]);

    LedgerFixtures::storeWork(
        input: LedgerFixtures::downloadInput($document->id, $validIntent),
        intentId: $validIntent,
    );

    $activated = app(ProcessTransaction::class)->run(
        fn (): ?string => app(IntentRepository::class)->activateNextLocked(
            'screening',
            SubjectKind::DocumentVersion->value,
            (string) $version->id,
        ),
    );

    expect($activated)->toBe($validIntent->value)
        ->and(DB::table('prc_operation_intents')->where('id', $orphanIntent->value)->value('state'))->toBe('pending')
        ->and(DB::table('prc_operation_intents')->where('id', $validIntent->value)->value('state'))->toBe('active')
        ->and(DB::table('prc_operation_intents')->where('id', $validIntent->value)->value('expected_head_generation'))->toBe(1)
        ->and(AuthorityRepository::headColumn(SubjectKind::DocumentVersion))->toBe('1::bigint AS head_generation');
});

test('StageFailedItem освобождает active intent и восстанавливает расписание ревалидации документа', function (): void {
    FinalizeFixtures::authority([StageCode::Download]);
    $document = Document::factory()->create(['next_revalidation_due_at' => null]);
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);
    DB::table('prc_documents')->where('id', $document->id)->update(['current_version_id' => $version->id]);

    $intentId = LedgerFixtures::uuid();
    app(ProcessTransaction::class)->run(function () use ($document, $intentId): void {
        app(WorkStoreRepository::class)->storeIntent(
            id: $intentId,
            intentKey: LedgerFixtures::hash('dl-intent:'.$intentId->value),
            stage: StageCode::Download,
            subject: new SubjectKey(SubjectKind::Document, $document->id),
            semanticPolicyHash: LedgerFixtures::hash('sem-dl'),
            accessPolicyHash: LedgerFixtures::hash('access'),
            cycleSeq: 1,
            expectedHeadGeneration: 1,
            state: IntentState::Active,
        );
    });

    $work = LedgerFixtures::storeWork(
        input: LedgerFixtures::downloadInput($document->id, $intentId),
        intentId: $intentId,
    );
    $lease = FinalizeFixtures::running($work);
    DB::table('prc_process_work')->where('id', $work->value->value)->update(['item_failures' => 2]);
    $outcome = new StageFailedItem(
        ItemFailureReason::ContractRejected,
        new ArtifactRef('fixture', 'evidence/download-404', LedgerFixtures::hash('dl-404')),
    );

    expect(app(WorkFinalizer::class)->finish($lease, $outcome)->status)->toBe(CompletionStatus::Applied)
        ->and(DB::table('prc_operation_intents')->where('id', $intentId->value)->value('state'))->toBe('terminal')
        ->and(DB::table('prc_documents')->where('id', $document->id)->value('next_revalidation_due_at'))->not->toBeNull();
});

test('FeederDaemon отправляет в карантин feeder_wake с неподдерживаемой payload_schema_version и ProcessControlCommand поддерживает retry-failed-item', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $messageId = LedgerFixtures::uuid()->value;
    DB::table('prc_process_outbox')->insert([
        'message_id' => $messageId,
        'dedup_key' => 'feed:acquisition:unsupported',
        'kind' => 'feeder_wake',
        'payload' => json_encode(['lane' => 'acquisition'], JSON_THROW_ON_ERROR),
        'payload_schema_version' => 2,
        'available_at' => DB::raw('clock_timestamp()'),
    ]);

    $tick = app(FeederDaemon::class)->tick();
    expect($tick->errorCode)->toBe('outbox_quarantined')
        ->and(DB::table('prc_process_outbox')->where('message_id', $messageId)->value('last_error_code'))
        ->toBe('quarantined_schema_unsupported');

    $work = ExecutionFixtures::listingWork();
    $lease = FinalizeFixtures::running($work);
    DB::table('prc_process_work')->where('id', $work->value->value)->update(['item_failures' => 2]);
    app(WorkFinalizer::class)->finish(
        $lease,
        new StageFailedItem(
            ItemFailureReason::InvalidInput,
            new ArtifactRef('fixture', 'evidence/bad-row', LedgerFixtures::hash('bad-row')),
        ),
    );
    expect(ExecutionFixtures::work($work)->state)->toBe('failed_item');

    $this->artisan('guard:sync-roles')->assertSuccessful();
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    $seq = (int) DB::table('prc_process_work')->where('id', $work->value->value)->value('transition_seq');

    $this->artisan('process:control', [
        'action' => 'retry-failed-item',
        'target' => $work->value->value,
        '--user' => (string) $operator->id,
        '--reason' => 'operator retry failed item',
        '--expected' => (string) $seq,
    ])->assertSuccessful();

    expect(DB::table('prc_process_work')->where('state', 'ready')->where('id', '!=', $work->value->value)->count())->toBe(1);
});

test('SubjectLockResolver::ownerSubject находит владельца при составной блокировке и RunReadRepository::correlationFor нормализует cohort_key площадки', function (): void {
    $document = Document::factory()->create();
    $version = DocumentVersion::factory()->create(['document_id' => $document->id]);

    $resolver = app(SubjectLockResolver::class);
    $docSubject = new SubjectKey(SubjectKind::Document, $document->id);
    $verSubject = new SubjectKey(SubjectKind::DocumentVersion, $version->id);
    $locked = [
        $docSubject->key() => new LockedSubject($docSubject, 1, null, $version->id),
        $verSubject->key() => new LockedSubject($verSubject, 1, null),
    ];

    $owner = $resolver->ownerSubject(StageCode::Screening, $locked);
    expect($owner->subject)->toEqual($verSubject);

    FinalizeFixtures::authority([StageCode::Listing]);
    $platform = Platform::factory()->create(['code' => 'eis-cohort-norm']);
    $block = LedgerFixtures::crawlBlock();
    $work = LedgerFixtures::storeWork(
        LedgerFixtures::listingInput($block['block'], $block['filter_hash'], $block['access_policy_hash']),
    );
    DB::table('prc_process_work')->where('id', $work->value->value)->update([
        'platform_id' => $platform->id,
        'cohort_key' => 'platform:'.$platform->id,
    ]);

    $correlation = app(RunReadRepository::class)->correlationFor($work);
    expect($correlation['cohort_key'] ?? null)->toBe('platform:eis-cohort-norm');
});
