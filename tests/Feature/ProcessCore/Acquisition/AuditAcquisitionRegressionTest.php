<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Dto\Procurement\Acquisition\ListingTask;
use App\Dto\Procurement\Platform\CursorEnvelope;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\SubjectKey;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\SubjectKind;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Crawler\ListingCoverageRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\ProcessCore\LedgerFixtures;

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
