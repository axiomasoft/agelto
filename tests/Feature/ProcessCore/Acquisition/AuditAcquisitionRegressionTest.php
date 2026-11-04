<?php

declare(strict_types=1);

use App\Actions\Procurement\Process\RetryHandlerFaultedWork;
use App\Dto\Platforms\Eis\EisListingCursor;
use App\Dto\Platforms\Eis\EisListingPolicy;
use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Dto\Procurement\Acquisition\DetailRequest;
use App\Dto\Procurement\Acquisition\ListingDetailReference;
use App\Dto\Procurement\Acquisition\ListingReceipt;
use App\Dto\Procurement\Acquisition\ListingTask;
use App\Dto\Procurement\Platform\CursorEnvelope;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\DetailInput;
use App\Dto\Procurement\Process\SubjectKey;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Platform\AssessmentReason;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Enums\Procurement\Process\WorkMutationStatus;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Crawler\DetailSnapshotRepository;
use App\Repositories\Procurement\Crawler\ListingCoverageRepository;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Repositories\Procurement\Process\ControlRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Platforms\Eis\EisListingPartitioner;
use App\Services\Procurement\Process\ProcessPolicy;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\ProcessCore\DetailFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ListingFixtures;

pest()->group('acquisition-operations', 'platform-adapter-contracts');

test('EisSearchFilter отвергает priceTo меньше priceFrom и принимает равные границы цены', function (): void {
    expect(fn () => EisSearchFilter::validateAndCreate([
        'okpd2Codes' => ['41.20'],
        'priceFrom' => 1000,
        'priceTo' => 500,
    ]))->toThrow(ValidationException::class);

    $valid = EisSearchFilter::validateAndCreate([
        'okpd2Codes' => ['41.20'],
        'priceFrom' => 1000,
        'priceTo' => 1000,
    ]);
    expect($valid->priceFrom)->toBe(1000)->and($valid->priceTo)->toBe(1000);
});

test('ListingCoverageRepository не отмечает окно observed при исходах только pass/split без observed/complete', function (): void {
    $block = LedgerFixtures::crawlBlock();
    $streamHex = str_repeat('ab', 32);
    $envelope = new CursorEnvelope('eis:listing:v1', 1, '{}');
    $task = new ListingTask(
        registryId: 1,
        registry: 'fz44',
        filter: $envelope,
        cursor: $envelope,
        rootCursor: $envelope,
        streamKey: new ContentHash($streamHex),
        windowStart: '2026-02-01',
        windowEnd: '2026-02-01',
        closesAt: '2026-02-02T00:00:00Z',
        rootBlockId: $block['block'],
        maxPartitions: 16,
    );

    DB::table('prc_crawl_blocks')->where('id', $block['block']->value)->update([
        'partition_payload' => $task->json(),
        'terminal_proof' => json_encode(['outcome' => 'pass'], JSON_THROW_ON_ERROR),
        'terminal_proof_schema_version' => 1,
    ]);

    $coverage = app(ListingCoverageRepository::class)->read(new ContentHash($streamHex));
    expect($coverage->observedThrough)->toBeNull()
        ->and($coverage->verifiedThrough)->toBeNull()
        ->and($coverage->debt)->toHaveCount(1);
});

test('RefreshRepository::reconcile находит просроченный next_due_at предыдущего успешного detail даже после неуспешного повторного detail intent', function (): void {
    $procurement = Procurement::factory()->create();
    $olderIntent = LedgerFixtures::uuid();
    $newerFailedIntent = LedgerFixtures::uuid();
    $run = LedgerFixtures::crawlBlock()['run'];

    app(ProcessTransaction::class)->run(function () use ($procurement, $olderIntent, $newerFailedIntent, $run): void {
        $store = app(WorkStoreRepository::class);
        $subject = new SubjectKey(SubjectKind::Procurement, $procurement->id);
        $store->storeIntent(
            id: $olderIntent,
            intentKey: LedgerFixtures::hash('detail-1:'.$olderIntent->value),
            stage: StageCode::Detail,
            subject: $subject,
            semanticPolicyHash: LedgerFixtures::hash('sem'),
            accessPolicyHash: LedgerFixtures::hash('acc'),
            cycleSeq: 1,
            expectedHeadGeneration: 1,
            state: IntentState::Terminal,
        );
        $work1 = LedgerFixtures::storeWork(
            input: LedgerFixtures::detailInput($procurement->id, $olderIntent),
            intentId: $olderIntent,
            originRunId: $run,
        );
        DB::table('prc_process_work')->where('id', $work1->value->value)->update(['state' => 'succeeded']);

        $store->storeIntent(
            id: $newerFailedIntent,
            intentKey: LedgerFixtures::hash('detail-2:'.$newerFailedIntent->value),
            stage: StageCode::Detail,
            subject: $subject,
            semanticPolicyHash: LedgerFixtures::hash('sem'),
            accessPolicyHash: LedgerFixtures::hash('acc'),
            cycleSeq: 2,
            expectedHeadGeneration: 1,
            state: IntentState::Terminal,
        );
    });

    DB::table('prc_operation_intents')->where('id', $olderIntent->value)->update([
        'next_due_at' => DB::raw("clock_timestamp() - interval '1 minute'"),
        'requested_at' => DB::raw("clock_timestamp() - interval '10 minutes'"),
    ]);
    DB::table('prc_operation_intents')->where('id', $newerFailedIntent->value)->update([
        'next_due_at' => null,
        'requested_at' => DB::raw('clock_timestamp()'),
    ]);

    $existsDue = DB::selectOne(
        "SELECT EXISTS(
            SELECT 1 FROM prc_operation_intents i
            WHERE i.subject_kind='prc_procurements' AND i.subject_id=?
              AND i.stage='detail' AND i.state='terminal' AND i.next_due_at<=clock_timestamp()
        ) AS due",
        [(string) $procurement->id],
    );
    expect($existsDue->due)->toBeTrue();
});

test('EisListingPartitioner::split соблюдает граничные инварианты минимальной ширины интервала, лимита партиций и равенства границ полуинтервалов', function (): void {
    $policy = new EisListingPolicy(minIntervalCents: 100, maxDepth: 5, maxPartitions: 16);
    $partitioner = new EisListingPartitioner($policy);
    $hash = LedgerFixtures::hash('eis-partition-boundary');

    // Ширина ровно равна minIntervalCents (100) — деление запрещено (Overflow).
    $atMinWidth = new EisListingCursor('2026-03-01', 'publish', 100, 200, $hash);
    $resMin = $partitioner->split($atMinWidth->envelope(), 1);
    expect($resMin->children)->toBe([])
        ->and($resMin->incompleteReason)->toBe(AssessmentReason::Overflow);

    // Ширина 101 > minIntervalCents (100), но allocatedPartitions + 2 > maxPartitions (15 + 2 > 16) — деление запрещено.
    $splittable = new EisListingCursor('2026-03-01', 'publish', 100, 201, $hash);
    $resMaxPart = $partitioner->split($splittable->envelope(), 15);
    expect($resMaxPart->children)->toBe([])
        ->and($resMaxPart->incompleteReason)->toBe(AssessmentReason::Overflow);

    // При allocatedPartitions = 14 (14 + 2 <= 16) интервал делится пополам с совпадением границы left->highCents === right->lowCents.
    $resOk = $partitioner->split($splittable->envelope(), 14);
    expect($resOk->children)->toHaveCount(2)
        ->and($resOk->incompleteReason)->toBeNull();

    $left = EisListingCursor::decode($resOk->children[0]);
    $right = EisListingCursor::decode($resOk->children[1]);
    expect($left->lowCents)->toBe(100)
        ->and($right->highCents)->toBe(201)
        ->and($left->highCents)->toBe($right->lowCents)
        ->and($left->depth)->toBe(1)
        ->and($right->depth)->toBe(1);
});

test('DetailSnapshotRepository::request изолирует запрос по intentId, не наследует старый :request чужого intent и сохраняет :request при manual-retry и re-pin', function (): void {
    DetailFixtures::bind();
    $fixture = DetailFixtures::work(value: 'audit-ag24-isolation');
    $procurement = $fixture['p'];
    $intentA = $fixture['input']->intentId;
    expect(app(DetailSnapshotRepository::class)->request($procurement->id, $intentA)->locator?->serializedPayload)->toBe('ea20');
    DB::table('prc_operation_intents')->where('id', $intentA->value)->update(['state' => 'terminal']);

    // Новый listing-блок порождает второй detail intent B с новым курсором card-v2 и ревизией rev-2.
    $listingRun = ListingFixtures::run(registry: $procurement->registry);
    $intentB = LedgerFixtures::uuid();
    $refB = new ListingDetailReference(
        intentId: $intentB,
        procurementId: $procurement->id,
        fingerprint: ContentHash::of('fp-2'),
        sourceRevision: 'rev-2',
        locator: new CursorEnvelope('eis.card', 1, 'card-v2'),
    );
    app(ProcessTransaction::class)->run(function () use ($procurement, $intentB, $listingRun, $refB): void {
        app(WorkStoreRepository::class)->storeIntent(
            id: $intentB,
            intentKey: LedgerFixtures::hash('detail-b:'.$intentB->value),
            stage: StageCode::Detail,
            subject: new SubjectKey(SubjectKind::Procurement, $procurement->id),
            semanticPolicyHash: LedgerFixtures::hash('semantic'),
            accessPolicyHash: LedgerFixtures::hash('access'),
            cycleSeq: 2,
            expectedHeadGeneration: $procurement->head_generation,
            state: IntentState::Active,
        );
        app(ListingStoreRepository::class)->receipt(
            $listingRun['input'],
            new ListingReceipt(ContentHash::of('ids'), [ContentHash::of('obs')], 'complete', details: [$refB]),
        );
    });

    // Запрос для intent B обязан резолвить его собственный ListingDetailReference (card-v2), а не старый intentA:request (ea20).
    $resolvedB = app(DetailSnapshotRepository::class)->request($procurement->id, $intentB);
    expect($resolvedB->locator?->serializedPayload)->toBe('card-v2');

    // Неизвестный intent C без собственного :request, :reference и manual-retry связи обязан падать, а не красть чужой :request.
    $unknownIntent = LedgerFixtures::uuid();
    expect(fn () => app(DetailSnapshotRepository::class)->request($procurement->id, $unknownIntent))
        ->toThrow(LogicException::class, 'Missing immutable detail request.');

    // Manual retry для blocked(handler_fault_limit) work intent B обязан скопировать DetailRequest под новый retryIntent и выдержать re-pin при росте head_generation.
    $workB = new WorkId(LedgerFixtures::uuid());
    $inputB = new DetailInput(
        $procurement->id,
        $intentB,
        $procurement->head_generation,
        LedgerFixtures::hash('semantic'),
        LedgerFixtures::hash('access'),
        'fixture-detail/1',
    );
    app(ProcessTransaction::class)->run(fn () => app(WorkStoreRepository::class)->storeWork(
        id: $workB,
        input: $inputB,
        inputRevision: $procurement->head_generation,
        intentId: $intentB,
        cohortKey: 'manual',
        platformId: null,
        originRunId: $listingRun['run'],
        priority: 100,
        eventId: LedgerFixtures::uuid(),
        projectionMessageId: LedgerFixtures::uuid(),
    ));
    DB::table('prc_process_work')->where('id', $workB->value->value)->update([
        'state' => 'blocked',
        'reason_code' => 'handler_fault_limit',
    ]);
    $transitionSeq = (int) DB::table('prc_process_work')->where('id', $workB->value->value)->value('transition_seq');

    app(ControlRepository::class)->ensureScopes(
        app(ProcessPolicy::class)->workScopes(StageCode::Detail, 'manual'),
    );
    $retryResult = app(RetryHandlerFaultedWork::class)->execute(
        $workB,
        $transitionSeq,
        LedgerFixtures::uuid(),
        'retry detail B',
    );
    expect($retryResult->status)->toBe(WorkMutationStatus::Applied)
        ->and($retryResult->successor)->not->toBeNull();
    $retryInput = DetailFixtures::input($retryResult->successor);
    $retryResolved = app(DetailSnapshotRepository::class)->request($procurement->id, $retryInput->intentId);
    expect($retryResolved->locator?->serializedPayload)->toBe('card-v2');

    // Рост head_generation и re-pin на retryIntent не теряют курсор card-v2.
    DB::table('prc_procurements')->where('id', $procurement->id)->update(['head_generation' => $procurement->head_generation + 1]);
    app(DetailSnapshotRepository::class)->saveRequest($procurement->id, $procurement->head_generation + 1, $retryInput->intentId, $retryResolved);
    $afterRepin = app(DetailSnapshotRepository::class)->request($procurement->id, $retryInput->intentId);
    expect($afterRepin->locator?->serializedPayload)->toBe('card-v2');
});

test('ListingStoreRepository проецирует ListingDetailReference в prc_detail_snapshots, выбирает хронологически новейшую ревизию вне зависимости от порядка UUID блока и поддерживает backfill', function (): void {
    $fixture = DetailFixtures::work(value: 'ag25-26-order');
    $procurement = $fixture['p'];
    DB::table('prc_operation_intents')->where('id', $fixture['input']->intentId->value)->update(['state' => 'terminal']);
    DB::table('prc_procurements')->where('id', $procurement->id)->update(['head_generation' => 2]);

    $run1 = ListingFixtures::run(from: '2026-01-01', registry: $procurement->registry);
    $run2 = ListingFixtures::run(from: '2026-01-02', registry: $procurement->registry);

    // Назначаем старой ревизии тот блок, чей UUID лексикографически БОЛЬШЕ, чем у блока новой ревизии.
    [$olderInput, $newerInput] = strcmp($run1['input']->crawlBlockId->value, $run2['input']->crawlBlockId->value) > 0
        ? [$run1['input'], $run2['input']]
        : [$run2['input'], $run1['input']];
    expect(strcmp($olderInput->crawlBlockId->value, $newerInput->crawlBlockId->value))->toBeGreaterThan(0);

    $olderIntent = LedgerFixtures::uuid();
    $newerIntent = LedgerFixtures::uuid();

    $olderRef = new ListingDetailReference(
        intentId: $olderIntent,
        procurementId: $procurement->id,
        fingerprint: ContentHash::of('fp-old'),
        sourceRevision: 'rev-old',
        locator: new CursorEnvelope('eis.card', 1, 'old-cursor'),
    );
    $newerRef = new ListingDetailReference(
        intentId: $newerIntent,
        procurementId: $procurement->id,
        fingerprint: ContentHash::of('fp-new'),
        sourceRevision: 'rev-new',
        locator: new CursorEnvelope('eis.card', 1, 'new-cursor'),
    );

    app(ProcessTransaction::class)->run(function () use ($procurement, $olderIntent, $newerIntent, $olderInput, $newerInput, $olderRef, $newerRef): void {
        $works = app(WorkStoreRepository::class);
        $subject = new SubjectKey(SubjectKind::Procurement, $procurement->id);
        $works->storeIntent($olderIntent, LedgerFixtures::hash('old:'.$olderIntent->value), StageCode::Detail, $subject,
            ContentHash::of('sem'), ContentHash::of('acc'), 2, 1, IntentState::Terminal);
        $works->storeIntent($newerIntent, LedgerFixtures::hash('new:'.$newerIntent->value), StageCode::Detail, $subject,
            ContentHash::of('sem'), ContentHash::of('acc'), 3, 2, IntentState::Active);

        $listings = app(ListingStoreRepository::class);
        $listings->receipt(
            $olderInput,
            new ListingReceipt(ContentHash::of('ids-old'), [ContentHash::of('obs-old')], 'complete', details: [$olderRef]),
        );
        $listings->receipt(
            $newerInput,
            new ListingReceipt(ContentHash::of('ids-new'), [ContentHash::of('obs-new')], 'complete', details: [$newerRef]),
        );
    });

    $listings = app(ListingStoreRepository::class);
    expect($listings->detailReference($olderIntent)?->locator?->serializedPayload)->toBe('old-cursor')
        ->and($listings->detailReference($newerIntent)?->locator?->serializedPayload)->toBe('new-cursor')
        ->and($listings->detailReferenceForProcurement($procurement->id)?->locator?->serializedPayload)->toBe('new-cursor')
        ->and($listings->detailReferenceForProcurement($procurement->id)?->sourceRevision)->toBe('rev-new');

    // Проверяем backfillDetailReferences() для исторических блоков, у которых ещё нет строки :reference в prc_detail_snapshots.
    DB::table('prc_detail_snapshots')->where('part_key', $newerIntent->value.':reference')->delete();
    expect($listings->detailReference($newerIntent))->toBeNull();
    expect($listings->backfillDetailReferences())->toBe(1)
        ->and($listings->backfillDetailReferences())->toBe(0)
        ->and($listings->detailReference($newerIntent)?->locator?->serializedPayload)->toBe('new-cursor');
});
