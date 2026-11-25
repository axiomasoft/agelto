<?php

declare(strict_types=1);

use App\Actions\Procurement\Process\CancelWork;
use App\Dto\Procurement\Process\FairnessCursor;
use App\Dto\Procurement\Process\InvariantViolation;
use App\Dto\Procurement\Process\ProviderRecoveryPlan;
use App\Dto\Procurement\Process\SubjectKey;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\AdmissionStatus;
use App\Enums\Procurement\Process\BlockedReason;
use App\Enums\Procurement\Process\CompletionStatus;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\InvariantKind;
use App\Enums\Procurement\Process\ProviderRecoveryMode;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Enums\Procurement\Process\WorkState;
use App\Models\Procurement\Document\Document;
use App\Repositories\Procurement\Process\AdmissionRepository;
use App\Repositories\Procurement\Process\IntentRepository;
use App\Repositories\Procurement\Process\InvariantRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Process\Contracts\ProviderRecoveryPolicy;
use App\Services\Procurement\Process\Contracts\ProviderRecoveryResolver;
use App\Services\Procurement\Process\ProcessAuthoritySync;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\RecoveryReconciler;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FixtureProviderRecoveryResolver;
use Tests\Support\ProcessCore\FixtureStagePayloadCodec;
use Tests\Support\ProcessCore\LedgerFixtures;

pest()->group('admission-fairness', 'claim-recovery-and-invariants');

test('сканирование обнаруживает неполную закрытую попытку и остаток резерва завершённой работы', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();
    $lease = FinalizeFixtures::running($work);
    $members = DB::table('prc_process_reservations')->where('work_id', $work->value->value)->get();
    $finished = app(WorkFinalizer::class)->finish(lease: $lease, outcome: FinalizeFixtures::succeeded(StageCode::Listing, 'done'));
    expect($finished->state)->toBe(WorkState::Succeeded);

    // Моделируем частично записанную историческую попытку и оставшиеся после release members.
    DB::table('prc_process_attempts')->where('id', $lease->attemptId->value)->update(['terminal_kind' => null]);
    DB::table('prc_process_reservations')->insert($members->map(static fn (object $row): array => (array) $row)->all());

    $violations = app(ProcessTransaction::class)->run(fn (): array => app(InvariantRepository::class)->readBatch(50));
    expect(array_map(static fn (InvariantViolation $item): InvariantKind => $item->kind, $violations))
        ->toBe([InvariantKind::ReleasedMembers, InvariantKind::AttemptState]);
});

test('активация intent читает head по первичному ключу подходящего типа', function (bool $uuid): void {
    $intent = LedgerFixtures::uuid();
    if ($uuid) {
        $block = LedgerFixtures::crawlBlock();
        $subject = new SubjectKey(SubjectKind::CrawlBlock, $block['block']);
        $input = LedgerFixtures::listingInput($block['block'], $block['filter_hash'], $block['access_policy_hash']);
        $subjectId = $block['block']->value;
        $cast = 'uuid';
    } else {
        $document = Document::factory()->create();
        $subject = new SubjectKey(SubjectKind::Document, $document->id);
        $input = LedgerFixtures::downloadInput($document->id, $intent);
        $subjectId = (string) $document->id;
        $cast = 'bigint';
    }
    app(WorkStoreRepository::class)->storeIntent(
        id: $intent,
        intentKey: LedgerFixtures::hash('pending:'.$intent->value),
        stage: $input->stage(),
        subject: $subject,
        semanticPolicyHash: LedgerFixtures::hash('semantic'),
        accessPolicyHash: LedgerFixtures::hash('access'),
        cycleSeq: 1,
        expectedHeadGeneration: 2,
        state: IntentState::Pending,
    );
    LedgerFixtures::storeWork(input: $input, intentId: $intent);
    $lookup = [];
    DB::listen(static function (QueryExecuted $query) use ($subject, &$lookup): void {
        if (str_contains($query->sql, ' FROM '.$subject->kind->value.' WHERE id')) {
            $lookup[] = $query->sql;
        }
    });

    $activated = app(ProcessTransaction::class)->run(fn (): ?string => app(IntentRepository::class)->activateNextLocked(
        stage: $input->stage()->value,
        subjectKind: $subject->kind->value,
        subjectId: $subjectId,
    ));

    $row = DB::table('prc_operation_intents')->where('id', $intent->value)->sole();
    expect([$activated, $row->state, $row->expected_head_generation])->toBe([$intent->value, 'active', 1])
        ->and($lookup)->toHaveCount(1)
        ->and($lookup[0])->toEndWith('WHERE id = ?::'.$cast);
})->with(['ключ UUID' => [true], 'ключ bigint' => [false]]);

test('подтверждённый receipt передаётся на settlement один раз для заблокированной и отменённой работы', function (bool $cancel): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();
    FinalizeFixtures::running($work);
    $unknown = FixtureProviderRecoveryResolver::plan(ProviderRecoveryMode::BlockedUnknown, FinalizeFixtures::inputHash($work));
    $resolver = new class($unknown) implements ProviderRecoveryPolicy, ProviderRecoveryResolver
    {
        /** @var list<ProviderRecoveryPlan> */
        public array $settled = [];

        public function __construct(public ProviderRecoveryPlan $finding) {}

        public function inspect(WorkId $workId, ?ProviderRecoveryPlan $pinned): ProviderRecoveryPlan
        {
            return $this->finding;
        }

        public function settleLocked(ProviderRecoveryPlan $plan): void
        {
            $this->settled[] = $plan;
        }

        public function revokeUnclaimedLocked(WorkId $workId): void {}

        public function terminalReason(ProviderRecoveryPlan $plan): ?BlockedReason
        {
            return null;
        }
    };
    $recovery = app(RecoveryReconciler::class);
    $recovery->bindProviderResolver($resolver);
    FinalizeFixtures::expire($work);
    expect($recovery->recoverExpiredRunning())->toBe(1)
        ->and(ExecutionFixtures::work($work)->state)->toBe('blocked');
    if ($cancel) {
        app(CancelWork::class)->execute(workId: $work, reason: 'operator cancel');
    } else {
        DB::table('prc_process_work')->where('id', $work->value->value)->update(['provider_recovery_due_at' => DB::raw('clock_timestamp()')]);
    }
    $resolver->finding = FixtureProviderRecoveryResolver::resolved(pinned: $unknown, mode: ProviderRecoveryMode::ResumeFromReceipt);

    expect($recovery->recoverBlockedProvider())->toBe(1)
        ->and($resolver->settled)->toBe([$resolver->finding])
        ->and(ExecutionFixtures::work($work)->state)->toBe($cancel ? 'cancelled' : 'ready');
})->with(['заблокированная' => [false], 'отменённая' => [true]]);

test('admission загружает authority конкурирующих когорт пачкой и читает payload только выбранной работы', function (): void {
    ExecutionFixtures::authority([StageCode::Listing]);
    $work = ExecutionFixtures::listingWork();
    ExecutionFixtures::ensureWorkScopes(stage: StageCode::Listing, cohort: 'manual');
    foreach (['platform:aaa', 'platform:bbb', 'platform:ccc'] as $cohort) {
        ExecutionFixtures::ensureWorkScopes(stage: StageCode::Listing, cohort: $cohort);
        ExecutionFixtures::listingWork($cohort);
    }
    $counts = ['work' => 0, 'handler' => 0, 'scopes' => 0, 'lanes' => 0];
    DB::listen(static function (QueryExecuted $query) use (&$counts): void {
        $key = match (true) {
            str_contains($query->sql, 'SELECT stage, cohort_key, input_revision') => 'work',
            str_contains($query->sql, 'prc_process_handler_holds') => 'handler',
            str_contains($query->sql, 'SELECT id, scope_key, desired_mode, control_version') => 'scopes',
            str_contains($query->sql, 'FROM prc_process_lanes WHERE lane_key IN') => 'lanes',
            default => null,
        };
        if ($key !== null) {
            $counts[$key]++;
        }
    });

    $result = app(AdmissionRepository::class)->admit(workId: $work, expectedCursor: FairnessCursor::empty(), eligibleCohorts: 4);

    expect($result->status)->toBe(AdmissionStatus::Admitted)
        ->and($counts)->toBe(['work' => 1, 'handler' => 4, 'scopes' => 2, 'lanes' => 2]);
});

test('синхронизация scopes, lanes и handler применяет ограничения ProcessTransaction', function (): void {
    $timeouts = [];
    DB::listen(static function (QueryExecuted $query) use (&$timeouts): void {
        if (str_starts_with($query->sql, 'SET LOCAL statement_timeout')) {
            $timeouts[] = $query->sql;
        }
    });
    $sync = app(ProcessAuthoritySync::class);
    $sync->ensureBaseline();
    $sync->registerHandler(stage: StageCode::Listing, handlerVersion: 'fixture-hot-path/1');

    expect($timeouts)->toBe(array_fill(0, 3, "SET LOCAL statement_timeout = '5s'"));
});

test('финализация использует версию из lease после смены зарегистрированного handler', function (): void {
    $results = FinalizeFixtures::authority([StageCode::Listing])['listing'];
    $work = ExecutionFixtures::listingWork();
    $lease = FinalizeFixtures::running($work);
    $registry = new StageRegistry;
    $registry->register(stage: StageCode::Listing, handlerVersion: 'fixture-new-listing/2');
    $registry->bindFinalization(stage: StageCode::Listing, codec: new FixtureStagePayloadCodec(StageCode::Listing), results: $results);
    app()->instance(StageRegistry::class, $registry);

    $result = app(WorkFinalizer::class)->finish(lease: $lease, outcome: FinalizeFixtures::succeeded(StageCode::Listing, 'done'));

    expect([$result->status, $result->state])->toBe([CompletionStatus::Applied, WorkState::Succeeded]);
});
