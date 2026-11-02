<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisListingCursor;
use App\Dto\Procurement\Acquisition\FetchDeferred;
use App\Dto\Procurement\Acquisition\ListingFetchResult;
use App\Dto\Procurement\Acquisition\ListingRequest;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\RetryAt;
use App\Dto\Procurement\Process\StageDeferred;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Repositories\Procurement\Crawler\DetailSnapshotRepository;
use App\Repositories\Procurement\Crawler\ListingCoverageRepository;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Services\Platforms\Eis\EisListingPartitioner;
use App\Services\Procurement\Acquisition\AcquisitionProfiles;
use App\Services\Procurement\Acquisition\AcquisitionScheduler;
use App\Services\Procurement\Acquisition\DetailArtifacts;
use App\Services\Procurement\Acquisition\DetailHandler;
use App\Services\Procurement\Acquisition\ListingHandler;
use App\Services\Procurement\Network\Contracts\OutboundAttemptGate;
use App\Services\Procurement\Platform\Contracts\ListingSource;
use App\Services\Procurement\Platform\FetchOutcomeBridge;
use App\Services\Procurement\Process\ExecutionContextFactory;
use App\Services\Procurement\Process\ProcessPayloadCodec;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\AcquisitionProfileFixtures;
use Tests\Support\ProcessCore\DetailFixtures;
use Tests\Support\ProcessCore\DetailFixtureSource;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\DownloadFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ListingFixtures;

function deferListingBudget(AcquisitionKind $kind): void
{
    ListingFixtures::bind();
    $profile = AcquisitionProfileFixtures::create($kind);
    $launch = app(AcquisitionProfiles::class)->prepare($profile, $kind, new DateTimeImmutable('2024-01-01'), new DateTimeImmutable('2024-01-01'));
    $run = app(AcquisitionScheduler::class)->manual($launch, LedgerFixtures::uuid(), null);
    $row = DB::table('prc_process_work')->where('origin_run_id', $run->value)->sole();
    $work = new WorkId(new ProcessUuid($row->id));
    $input = app(ProcessPayloadCodec::class)->decodeInput(new VersionedPayload($row->input_payload, 1));
    $store = app(ListingStoreRepository::class);
    $task = $store->task($input);
    $retry = new RetryAt(new DateTimeImmutable('+10 minutes'));
    $source = new class($retry) implements ListingSource
    {
        public function __construct(private readonly RetryAt $retry) {}

        public function fetch(ListingRequest $request, ExecutionContext|OutboundExecutionContext $context): ListingFetchResult
        {
            return new FetchDeferred(NetworkDeferralReason::RateLimit, $this->retry);
        }
    };
    $planner = new EisListingPartitioner;
    $handler = new ListingHandler($store, $source, $planner, $planner, new FetchOutcomeBridge);
    $lease = FinalizeFixtures::running($work);
    $out = $handler->execute($input, app(ExecutionContextFactory::class)->open($lease));
    app(WorkFinalizer::class)->finish($lease, $out);
    $after = ExecutionFixtures::work($work);
    $coverage = app(ListingCoverageRepository::class)->read($launch->streamKey);
    expect($after->state)->toBe('deferred')->and($after->reason_code)->toBe('rate_limit')
        ->and($after->item_failures)->toBe(0)->and($after->owner_token)->toBeNull()
        ->and(new DateTimeImmutable($after->due_at))->toBeGreaterThanOrEqual($retry->value)
        ->and($store->task($input)->cursor)->toEqual($task->cursor)
        ->and(EisListingCursor::decode($task->cursor)->dateField)->toBe($kind === AcquisitionKind::Delta ? 'update' : 'publish')
        ->and(DB::table('prc_crawl_blocks')->count())->toBe(1)
        ->and(DB::table('prc_process_runs')->where('id', $run->value)->sole()->discovery_outcome)->toBe('collecting')
        ->and($coverage->verifiedThrough)->toBeNull()->and($coverage->debt)->toHaveCount(1)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
}

test('исчерпание бюджета листинга завершает срез чекпоинтом без падения прогона',
    fn () => deferListingBudget(AcquisitionKind::Fresh));

test('исчерпание бюджета archive сохраняет недренированный день и не переводит run в failed',
    fn () => deferListingBudget(AcquisitionKind::Archive));

test('исчерпание бюджета delta сохраняет недренированный день до следующего cron',
    fn () => deferListingBudget(AcquisitionKind::Delta));

test('исчерпание бюджета backlog не помечает fresh-run rescanned',
    fn () => deferListingBudget(AcquisitionKind::Archive));

test('исчерпание бюджета карточки не растит detail_attempts и не двигает retry', function (): void {
    DetailFixtures::bind();
    $f = DetailFixtures::work();
    DB::table('prc_process_work')->where('id', $f['work']->value->value)->update(['item_failures' => 2]);
    $retry = new RetryAt(new DateTimeImmutable('+10 minutes'));
    $source = new DetailFixtureSource([new FetchDeferred(NetworkDeferralReason::RateLimit, $retry)]);
    $handler = new DetailHandler(app(DetailSnapshotRepository::class), $source, app(DetailArtifacts::class), new FetchOutcomeBridge);
    $lease = FinalizeFixtures::running($f['work']);
    app(WorkFinalizer::class)->finish($lease, $handler->execute($f['input'], app(ExecutionContextFactory::class)->open($lease)));
    $row = ExecutionFixtures::work($f['work']);
    expect($row->state)->toBe('deferred')->and($row->item_failures)->toBe(2)
        ->and($row->reason_code)->toBe('rate_limit')->and(new DateTimeImmutable($row->due_at))->toBeGreaterThanOrEqual($retry->value)
        ->and(DB::table('prc_detail_snapshots')->where('part_key', 'like', '%:published')->count())->toBe(0);
});

test('исчерпание бюджета документа не записывает error-блок документа', function (): void {
    PermitFixtures::clean();
    ExecutionFixtures::truncateLedger();
    DocumentStorageFixtures::reset();
    $wire = new WireFixture;
    try {
        $f = new DownloadFixtures($wire);
        [$input,$work,$ctx] = $f->running();
        DB::table('prc_process_work')->where('id', $work->value->value)->update(['item_failures' => 2]);
        $gate = Mockery::mock(OutboundAttemptGate::class);
        $gate->shouldReceive('authorize')->once()->andReturn(new TransportDeferred(NetworkDeferralReason::RateLimit, new RetryAt(new DateTimeImmutable('+1 minute'))));
        $outcome = $f->handler($gate)->execute($input, $ctx);
        expect($outcome)->toBeInstanceOf(StageDeferred::class);
        app(WorkFinalizer::class)->finish($ctx->lease(), $outcome);
        expect(DB::table('prc_process_work')->where('id', $work->value->value)->value('item_failures'))->toBe(2)
            ->and(DB::table('prc_document_versions')->count())->toBe(0)->and($wire->receipts())->toBeEmpty();
    } finally {
        $wire->stop();
    }
});
