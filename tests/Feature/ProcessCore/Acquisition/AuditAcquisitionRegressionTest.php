<?php

declare(strict_types=1);

use App\Actions\Procurement\Process\RetryHandlerFaultedWork;
use App\Dto\Platforms\Eis\EisListingCursor;
use App\Dto\Platforms\Eis\EisListingFilter;
use App\Dto\Platforms\Eis\EisListingPolicy;
use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Dto\Procurement\Acquisition\AcquisitionConfiguration;
use App\Dto\Procurement\Acquisition\DetailRequest;
use App\Dto\Procurement\Acquisition\ListingDetailReference;
use App\Dto\Procurement\Acquisition\ListingReceipt;
use App\Dto\Procurement\Acquisition\ListingTask;
use App\Dto\Procurement\Network\ProxyNodeConfiguration;
use App\Dto\Procurement\Platform\CursorEnvelope;
use App\Dto\Procurement\Platform\PlatformCode;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\DetailInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\SubjectKey;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Platform\AssessmentReason;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Enums\Procurement\Process\WorkMutationStatus;
use App\Models\Procurement\Procurement;
use App\Models\User;
use App\Repositories\Procurement\Crawler\DetailSnapshotRepository;
use App\Repositories\Procurement\Crawler\ListingCoverageRepository;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Repositories\Procurement\Network\RouteRepository;
use App\Repositories\Procurement\Process\ControlRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Platforms\Eis\EisListingPartitioner;
use App\Services\Procurement\Acquisition\AcquisitionHandler;
use App\Services\Procurement\Acquisition\AcquisitionProfiles;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Platform\PlatformRegistry;
use App\Services\Procurement\Process\ProcessPolicy;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\AcquisitionProfileFixtures;
use Tests\Support\ProcessCore\DetailFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ListingFixtures;

pest()->group('acquisition-operations', 'platform-adapter-contracts');

function auditAcqStoreDetailIntent(
    int $procurementId,
    ProcessUuid $intentId,
    string $keyPrefix,
    int $cycleSeq,
    int $expectedHeadGeneration = 1,
    IntentState $state = IntentState::Terminal,
): void {
    app(WorkStoreRepository::class)->storeIntent(
        id: $intentId,
        intentKey: LedgerFixtures::hash($keyPrefix.':'.$intentId->value),
        stage: StageCode::Detail,
        subject: new SubjectKey(SubjectKind::Procurement, $procurementId),
        semanticPolicyHash: LedgerFixtures::hash('sem'),
        accessPolicyHash: LedgerFixtures::hash('acc'),
        cycleSeq: $cycleSeq,
        expectedHeadGeneration: $expectedHeadGeneration,
        state: $state,
    );
}

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
        auditAcqStoreDetailIntent($procurement->id, $olderIntent, 'detail-1', 1);
        $work1 = LedgerFixtures::storeWork(
            input: LedgerFixtures::detailInput($procurement->id, $olderIntent),
            intentId: $olderIntent,
            originRunId: $run,
        );
        DB::table('prc_process_work')->where('id', $work1->value->value)->update(['state' => 'succeeded']);

        auditAcqStoreDetailIntent($procurement->id, $newerFailedIntent, 'detail-2', 2);
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
        auditAcqStoreDetailIntent(
            $procurement->id,
            $intentB,
            'detail-b',
            2,
            $procurement->head_generation,
            IntentState::Active,
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
        auditAcqStoreDetailIntent($procurement->id, $olderIntent, 'old', 2, 1, IntentState::Terminal);
        auditAcqStoreDetailIntent($procurement->id, $newerIntent, 'new', 3, 2, IntentState::Active);

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
    $firstBackfill = $listings->backfillDetailReferences();
    expect($firstBackfill['processed'])->toBe(2)
        ->and($firstBackfill['inserted'])->toBe(1)
        ->and($firstBackfill['next_block_id']?->value)->toBe($olderInput->crawlBlockId->value)
        ->and($firstBackfill['next_ordinal'])->toBe(1)
        ->and($firstBackfill['done'])->toBeTrue();
    $secondBackfill = $listings->backfillDetailReferences();
    expect($secondBackfill['processed'])->toBe(2)
        ->and($secondBackfill['inserted'])->toBe(0)
        ->and($secondBackfill['next_block_id']?->value)->toBe($olderInput->crawlBlockId->value)
        ->and($secondBackfill['next_ordinal'])->toBe(1)
        ->and($secondBackfill['done'])->toBeTrue()
        ->and($listings->detailReference($newerIntent)?->locator?->serializedPayload)->toBe('new-cursor');
});

test('ListingStoreRepository::backfillDetailReferences пагинирует массив details внутри одного и нескольких блоков по составному курсору, требует транзакцию и откатывает батч при конфликте неизменности', function (): void {
    $outsideTx = ConcurrentTransactions::execute(<<<'PHP'
try {
    app(\App\Repositories\Procurement\Crawler\ListingStoreRepository::class)->backfillDetailReferences();

    return ['thrown' => false, 'level' => \Illuminate\Support\Facades\DB::connection()->transactionLevel()];
} catch (\LogicException $e) {
    return [
        'thrown' => true,
        'message' => $e->getMessage(),
        'level' => \Illuminate\Support\Facades\DB::connection()->transactionLevel(),
    ];
}
PHP);
    expect($outsideTx)->toBe([
        'thrown' => true,
        'message' => 'Listing detail reference backfill must run inside the caller ledger transaction.',
        'level' => 0,
    ]);

    $listings = app(ListingStoreRepository::class);
    $someBlockId = LedgerFixtures::uuid();
    expect(fn () => $listings->backfillDetailReferences(null, 0, 0))
        ->toThrow(InvalidArgumentException::class, 'Invalid listing detail reference backfill cursor or limit.')
        ->and(fn () => $listings->backfillDetailReferences(null, 0, 5001))
        ->toThrow(InvalidArgumentException::class, 'Invalid listing detail reference backfill cursor or limit.')
        ->and(fn () => $listings->backfillDetailReferences($someBlockId, -1, 10))
        ->toThrow(InvalidArgumentException::class, 'Invalid listing detail reference backfill cursor or limit.')
        ->and(fn () => $listings->backfillDetailReferences(null, 1, 10))
        ->toThrow(InvalidArgumentException::class, 'Invalid listing detail reference backfill cursor or limit.');

    $fixture = DetailFixtures::work(value: 'backfill-cursor-pagination');
    $procurement = $fixture['p'];
    DB::table('prc_operation_intents')->where('id', $fixture['input']->intentId->value)->update(['state' => 'terminal']);

    $runA = ListingFixtures::run(from: '2026-01-01', registry: $procurement->registry);
    $runB = ListingFixtures::run(from: '2026-01-02', registry: $procurement->registry);
    $runEmpty = ListingFixtures::run(from: '2026-01-03', registry: $procurement->registry);

    $inputs = [$runA['input'], $runB['input'], $runEmpty['input']];
    usort($inputs, static fn ($left, $right): int => strcmp($left->crawlBlockId->value, $right->crawlBlockId->value));
    [$firstBlock, $emptyBlock, $secondBlock] = $inputs;

    $intents = [
        LedgerFixtures::uuid(),
        LedgerFixtures::uuid(),
        LedgerFixtures::uuid(),
        LedgerFixtures::uuid(),
        LedgerFixtures::uuid(),
    ];
    $refs = [];
    foreach ($intents as $idx => $intentId) {
        $seq = $idx + 1;
        $refs[] = new ListingDetailReference(
            intentId: $intentId,
            procurementId: $procurement->id,
            fingerprint: ContentHash::of('fp-'.$seq),
            sourceRevision: 'rev-'.$seq,
            locator: new CursorEnvelope('eis.card', 1, 'cursor-'.$seq),
        );
    }

    app(ProcessTransaction::class)->run(function () use ($procurement, $intents, $firstBlock, $emptyBlock, $secondBlock, $refs, $listings): void {
        foreach ($intents as $idx => $intentId) {
            auditAcqStoreDetailIntent($procurement->id, $intentId, 'bf-'.$idx, $idx + 1, $idx + 1, IntentState::Terminal);
        }

        // Первый блок содержит 3 ссылки (> limit = 2), средний — пустой массив details, третий — 2 ссылки.
        $listings->receipt(
            $firstBlock,
            new ListingReceipt(ContentHash::of('ids-1'), [ContentHash::of('obs-1')], 'complete', details: [$refs[0], $refs[1], $refs[2]]),
        );
        $listings->receipt(
            $emptyBlock,
            new ListingReceipt(ContentHash::of('ids-empty'), [], 'complete', details: []),
        );
        $listings->receipt(
            $secondBlock,
            new ListingReceipt(ContentHash::of('ids-2'), [ContentHash::of('obs-2')], 'complete', details: [$refs[3], $refs[4]]),
        );
    });

    // Удаляем все материализованные :reference снимки, чтобы протестировать чистый пошаговый backfill по 2 ссылки за батч.
    DB::table('prc_detail_snapshots')->where('procurement_id', $procurement->id)->where('part_key', 'like', '%:reference')->delete();
    foreach ($intents as $intentId) {
        expect($listings->detailReference($intentId))->toBeNull();
    }

    // Батч 1: берёт первые 2 ссылки из firstBlock (ordinal 1 и 2), хвост firstBlock (ordinal 3) остаётся на следующий батч.
    $batch1 = app(ProcessTransaction::class)->run(fn (): array => $listings->backfillDetailReferences(null, 0, 2));
    expect($batch1['processed'])->toBe(2)
        ->and($batch1['inserted'])->toBe(2)
        ->and($batch1['next_block_id']?->value)->toBe($firstBlock->crawlBlockId->value)
        ->and($batch1['next_ordinal'])->toBe(2)
        ->and($batch1['done'])->toBeFalse()
        ->and($listings->detailReference($intents[0])?->locator?->serializedPayload)->toBe('cursor-1')
        ->and($listings->detailReference($intents[1])?->locator?->serializedPayload)->toBe('cursor-2')
        ->and($listings->detailReference($intents[2]))->toBeNull();

    // Повтор батча 1 до сохранения курсора (inserted === 0 при processed === 2) всё равно двигает курсор и возвращает done: false.
    $replayBatch1 = app(ProcessTransaction::class)->run(fn (): array => $listings->backfillDetailReferences(null, 0, 2));
    expect($replayBatch1['processed'])->toBe(2)
        ->and($replayBatch1['inserted'])->toBe(0)
        ->and($replayBatch1['next_block_id']?->value)->toBe($firstBlock->crawlBlockId->value)
        ->and($replayBatch1['next_ordinal'])->toBe(2)
        ->and($replayBatch1['done'])->toBeFalse();

    // Батч 2: добирает хвост firstBlock (ordinal 3), пропускает пустой emptyBlock и берёт первую ссылку secondBlock (ordinal 1).
    $batch2 = app(ProcessTransaction::class)->run(
        fn (): array => $listings->backfillDetailReferences($batch1['next_block_id'], $batch1['next_ordinal'], 2),
    );
    expect($batch2['processed'])->toBe(2)
        ->and($batch2['inserted'])->toBe(2)
        ->and($batch2['next_block_id']?->value)->toBe($secondBlock->crawlBlockId->value)
        ->and($batch2['next_ordinal'])->toBe(1)
        ->and($batch2['done'])->toBeFalse()
        ->and($listings->detailReference($intents[2])?->locator?->serializedPayload)->toBe('cursor-3')
        ->and($listings->detailReference($intents[3])?->locator?->serializedPayload)->toBe('cursor-4')
        ->and($listings->detailReference($intents[4]))->toBeNull();

    // Батч 3: добирает оставшуюся 5-ю ссылку из secondBlock (ordinal 2) и сообщает done: true.
    $batch3 = app(ProcessTransaction::class)->run(
        fn (): array => $listings->backfillDetailReferences($batch2['next_block_id'], $batch2['next_ordinal'], 2),
    );
    expect($batch3['processed'])->toBe(1)
        ->and($batch3['inserted'])->toBe(1)
        ->and($batch3['next_block_id']?->value)->toBe($secondBlock->crawlBlockId->value)
        ->and($batch3['next_ordinal'])->toBe(2)
        ->and($batch3['done'])->toBeTrue()
        ->and($listings->detailReference($intents[4])?->locator?->serializedPayload)->toBe('cursor-5');

    // Вызов после финального курсора возвращает пустой результат и сохраняет курсор.
    $afterEnd = app(ProcessTransaction::class)->run(
        fn (): array => $listings->backfillDetailReferences($batch3['next_block_id'], $batch3['next_ordinal'], 2),
    );
    expect($afterEnd['processed'])->toBe(0)
        ->and($afterEnd['inserted'])->toBe(0)
        ->and($afterEnd['next_block_id']?->value)->toBe($secondBlock->crawlBlockId->value)
        ->and($afterEnd['next_ordinal'])->toBe(2)
        ->and($afterEnd['done'])->toBeTrue();

    // Проверяем атомарный откат батча при конфликте неизменности во второй ссылке батча.
    DB::table('prc_detail_snapshots')
        ->where('part_key', $intents[0]->value.':reference')
        ->delete();
    DB::table('prc_detail_snapshots')
        ->where('part_key', $intents[1]->value.':reference')
        ->update(['source_version' => 'tampered-revision']);

    expect(fn () => app(ProcessTransaction::class)->run(fn (): array => $listings->backfillDetailReferences(null, 0, 2)))
        ->toThrow(LogicException::class, 'Immutable listing detail reference conflict.');

    // Вставка первой ссылки из того же батча должна быть откачена вместе с транзакцией батча.
    expect($listings->detailReference($intents[0]))->toBeNull();
});

test('конфигурация сбора поддерживает раздельные прокси-профили стадий listing detail и status_refresh с обратной совместимостью со схемами v1 и v2', function (): void {
    PermitFixtures::clean();
    $defaultRouteProfile = LedgerFixtures::uuid();
    $listingRouteProfile = LedgerFixtures::uuid();
    $detailRouteProfile = LedgerFixtures::uuid();
    $filter = new EisListingFilter(['12345']);

    // Проверяем схему v2 (обратная совместимость для уже созданных run)
    $v2 = new AcquisitionConfiguration(
        profileVersion: 1,
        routeProfileId: $defaultRouteProfile,
        routePolicyVersion: 1,
        kind: AcquisitionKind::Archive,
        initialFrom: new DateTimeImmutable('2025-01-01'),
        through: new DateTimeImmutable('2025-01-31'),
        requestedFrom: new DateTimeImmutable('2025-01-01'),
        requestedThrough: new DateTimeImmutable('2025-01-31'),
    );
    $v2Payload = $v2->encode();
    expect($v2Payload->schemaVersion)->toBe(2)
        ->and($v2->stageRouteProfiles)->toBe([])
        ->and($v2->routeProfileIdForStage(StageCode::Listing)->value)->toBe($defaultRouteProfile->value)
        ->and($v2->routeProfileIdForStage(StageCode::Detail)->value)->toBe($defaultRouteProfile->value);

    // Проверяем схему v3 с раздельными профилями стадий
    $v3 = new AcquisitionConfiguration(
        profileVersion: 1,
        routeProfileId: $defaultRouteProfile,
        routePolicyVersion: 1,
        kind: AcquisitionKind::Archive,
        initialFrom: new DateTimeImmutable('2025-01-01'),
        through: new DateTimeImmutable('2025-01-31'),
        requestedFrom: new DateTimeImmutable('2025-01-01'),
        requestedThrough: new DateTimeImmutable('2025-01-31'),
        stageRouteProfiles: [
            'listing' => ['route_profile_id' => $listingRouteProfile, 'route_policy_version' => 1],
            'detail' => ['route_profile_id' => $detailRouteProfile, 'route_policy_version' => 1],
            'status_refresh' => ['route_profile_id' => $detailRouteProfile, 'route_policy_version' => 1],
        ],
    );
    $v3Payload = $v3->encode();
    expect($v3Payload->schemaVersion)->toBe(3);
    $decodedV3 = AcquisitionConfiguration::decode($v3Payload);
    expect($decodedV3->routeProfileIdForStage(StageCode::Listing)->value)->toBe($listingRouteProfile->value)
        ->and($decodedV3->routeProfileIdForStage(StageCode::Detail)->value)->toBe($detailRouteProfile->value)
        ->and($decodedV3->routeProfileIdForStage(StageCode::StatusRefresh)->value)->toBe($detailRouteProfile->value);

    // Проверяем, что EisProfilePreparer и AcquisitionHandler резолвят наполненные профили eis-listing и eis-detail
    $profile = AcquisitionProfileFixtures::create(AcquisitionKind::Archive);
    $actor = User::factory()->create();
    $routes = app(RouteRepository::class);
    $tx = app(ProcessTransaction::class);
    try {
        $definition = app(PlatformRegistry::class)->get(new PlatformCode('eis'));
        app(SourceCatalogSync::class)->sync($definition->sourceTargets);

        $pListing = $tx->run(fn () => $routes->storeProxy(
            null, new ProxyNodeConfiguration(
                service: 'stage-listing-proxy', host: '198.51.100.51', port: 8080,
                username: null, country: 'RU', scopes: ['general'], secretRef: null, enabled: true,
            ),
            $actor->id, 'listing proxy', ProcessUuid::random(), ProcessUuid::random(),
        ));
        $pDetail = $tx->run(fn () => $routes->storeProxy(
            null, new ProxyNodeConfiguration(
                service: 'stage-detail-proxy', host: '198.51.100.52', port: 8080,
                username: null, country: 'RU', scopes: ['general'], secretRef: null, enabled: true,
            ),
            $actor->id, 'detail proxy', ProcessUuid::random(), ProcessUuid::random(),
        ));
        $rListing = (string) $pListing->route_id;
        $rDetail = (string) $pDetail->route_id;

        $tx->run(fn () => $routes->ensureProfile('default', $rListing));
        $tx->run(fn () => $routes->ensureProfile('eis-listing'));
        $tx->run(fn () => $routes->ensureProfile('eis-detail'));

        $listingProfRow = DB::table('prc_network_route_profiles')->where('profile_key', 'eis-listing')->sole();
        $detailProfRow = DB::table('prc_network_route_profiles')->where('profile_key', 'eis-detail')->sole();
        $defaultProfRow = DB::table('prc_network_route_profiles')->where('profile_key', 'default')->sole();

        $tx->run(fn () => $routes->updateProfile('eis-listing', (int) $listingProfRow->policy_version, [$rListing], $actor->id, 'bind listing', ProcessUuid::random(), ProcessUuid::random()));
        $tx->run(fn () => $routes->updateProfile('eis-detail', (int) $detailProfRow->policy_version, [$rDetail], $actor->id, 'bind detail', ProcessUuid::random(), ProcessUuid::random()));

        $profile->update(['route_profile_id' => $defaultProfRow->id]);
        $launch = app(AcquisitionProfiles::class)->prepare(
            $profile, AcquisitionKind::Archive, new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2025-01-02'),
        );
        $decodedLaunch = AcquisitionConfiguration::decode($launch->configuration);
        expect($launch->configuration->schemaVersion)->toBe(3)
            ->and($decodedLaunch->routeProfileIdForStage(StageCode::Listing)->value)->toBe($listingProfRow->id)
            ->and($decodedLaunch->routeProfileIdForStage(StageCode::Detail)->value)->toBe($detailProfRow->id)
            ->and($decodedLaunch->routeProfileIdForStage(StageCode::StatusRefresh)->value)->toBe($detailProfRow->id);

        // Для уже созданного прогона со схемой v2 AcquisitionHandler также резолвит наполненные профили стадий платформы
        $handlerListing = app()->makeWith(AcquisitionHandler::class, ['stage' => StageCode::Listing]);
        $handlerDetail = app()->makeWith(AcquisitionHandler::class, ['stage' => StageCode::Detail]);
        $resolveMethod = new ReflectionMethod(AcquisitionHandler::class, 'resolveStageRouteProfileId');

        expect($resolveMethod->invoke($handlerListing, $definition, $v2)->value)->toBe($listingProfRow->id)
            ->and($resolveMethod->invoke($handlerDetail, $definition, $v2)->value)->toBe($detailProfRow->id);
    } finally {
        DB::table('prc_proxies')->whereIn('service', ['stage-listing-proxy', 'stage-detail-proxy'])->delete();
        $profile->delete();
        $actor->delete();
        PermitFixtures::clean();
    }
});

test('команда process:diagnose с флагом --proxy-budgets выводит бюджеты профили стадий и статистику запросов 403/429 по каждому прокси', function (): void {
    PermitFixtures::clean();
    $f = new PermitFixtures;
    $ctx = $f->context();
    $profile = AcquisitionProfileFixtures::create();
    $actor = User::factory()->create();
    $routes = app(RouteRepository::class);
    $tx = app(ProcessTransaction::class);
    try {
        $definition = app(PlatformRegistry::class)->get(new PlatformCode('eis'));
        app(SourceCatalogSync::class)->sync($definition->sourceTargets);
        $origin = $definition->sourceTargets->origins()[0];
        $targetId = (string) DB::table('prc_source_targets')
            ->where('platform_id', $profile->registry->platform_id)
            ->where('target_key', 'card')
            ->value('id');

        $p1 = $tx->run(fn () => $routes->storeProxy(
            null, new ProxyNodeConfiguration(
                service: 'diag-proxy-1', host: '198.51.100.61', port: 8081,
                username: null, country: 'RU', scopes: ['general'], secretRef: null, enabled: true,
            ),
            $actor->id, 'diag proxy 1', ProcessUuid::random(), ProcessUuid::random(),
        ));
        $p2 = $tx->run(fn () => $routes->storeProxy(
            null, new ProxyNodeConfiguration(
                service: 'diag-proxy-2', host: '198.51.100.62', port: 8082,
                username: null, country: 'RU', scopes: ['general'], secretRef: null, enabled: true,
            ),
            $actor->id, 'diag proxy 2', ProcessUuid::random(), ProcessUuid::random(),
        ));
        $r1 = (string) $p1->route_id;
        $r2 = (string) $p2->route_id;
        app(SourceCatalogSync::class)->sync($definition->sourceTargets);

        $tx->run(fn () => $routes->ensureProfile('eis-listing'));
        $tx->run(fn () => $routes->ensureProfile('eis-detail'));
        $listingProf = DB::table('prc_network_route_profiles')->where('profile_key', 'eis-listing')->sole();
        $detailProf = DB::table('prc_network_route_profiles')->where('profile_key', 'eis-detail')->sole();
        $tx->run(fn () => $routes->updateProfile('eis-listing', (int) $listingProf->policy_version, [$r1], $actor->id, 'bind listing', ProcessUuid::random(), ProcessUuid::random()));
        $tx->run(fn () => $routes->updateProfile('eis-detail', (int) $detailProf->policy_version, [$r2], $actor->id, 'bind detail', ProcessUuid::random(), ProcessUuid::random()));

        // Записываем запрос 200 на r1 и запрос 403 + cooldown на r2
        $now = (new DateTimeImmutable)->format('Y-m-d H:i:s.uP');
        $originBudgetId = (string) DB::table('prc_network_budgets')->where('scope_key', $origin->budgetScopeKey())->value('id');
        foreach ([[$r1, 200, 1], [$r2, 403, 2]] as [$rid, $st, $seq]) {
            $demandId = LedgerFixtures::uuid()->value;
            $reqId = LedgerFixtures::uuid()->value;
            DB::table('prc_network_demands')->insert([
                'id' => $demandId, 'physical_request_id' => $reqId,
                'operation_id' => $ctx->lease()->id->value, 'operation_generation' => 1, 'origin_budget_id' => $originBudgetId,
                'route_id' => $rid, 'target_id' => $targetId, 'traffic_class' => 'detail', 'purpose' => 'ordinary',
                'state' => 'granted', 'registered_at' => $now, 'renewed_at' => $now, 'expires_at' => $now,
                'operation_deadline' => $ctx->lease()->deadlineAt,
            ]);
            DB::table('prc_request_attempts')->insert([
                'request_id' => $reqId, 'demand_id' => $demandId, 'request_seq' => $seq,
                'target_id' => $targetId, 'route_id' => $rid, 'started_at' => $now, 'sent_at' => $now,
                'finished_at' => $now, 'phase' => 'finished', 'outcome' => 'response', 'status' => $st,
                'epochs' => '{}', 'epochs_schema_version' => 1,
            ]);
        }
        DB::table('prc_network_budgets')
            ->where('scope_key', $origin->routeScopeKey($r2))
            ->update(['not_before' => DB::raw("clock_timestamp() + interval '5 minutes'")]);

        $exit = Artisan::call('process:diagnose', ['platform' => 'eis', '--proxy-budgets' => true]);
        $output = Artisan::output();

        expect($exit)->toBe(0)
            ->and($output)->toContain('Стадия listing: профиль=eis-listing')
            ->and($output)->toContain('Стадия detail: профиль=eis-detail')
            ->and($output)->toContain('rate_per_minute=60, emission_interval_us=1000000, burst=2, max_inflight=10')
            ->and($output)->toContain('198.51.100.61:8081')
            ->and($output)->toContain('198.51.100.62:8082')
            ->and($output)->toContain('10000000')
            ->and($output)->toContain('укладывается в бюджет')
            ->and($output)->toContain('cooldown до');
    } finally {
        DB::table('prc_proxies')->whereIn('service', ['diag-proxy-1', 'diag-proxy-2'])->delete();
        $profile->delete();
        $actor->delete();
        PermitFixtures::clean();
    }
});
