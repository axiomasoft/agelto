<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisListingCursor;
use App\Dto\Platforms\Eis\EisListingFilter;
use App\Dto\Platforms\Eis\EisListingPolicy;
use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Procurement\Acquisition\ListingPage;
use App\Dto\Procurement\Acquisition\ListingResult;
use App\Dto\Procurement\Acquisition\ProcurementSummaries;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Dto\Procurement\Platform\ResponseAssessment;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Enums\Procurement\Platform\AssessmentReason;
use App\Enums\Procurement\Platform\Completeness;
use App\Enums\Procurement\Platform\TerminalProof;
use App\Enums\Procurement\Process\StageCode;
use App\Repositories\Procurement\Crawler\ListingCoverageRepository;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Services\Platforms\Eis\EisListingPartitioner;
use App\Services\Platforms\Eis\EisListingSource;
use App\Services\Procurement\Acquisition\ListingHandler;
use App\Services\Procurement\Platform\FetchOutcomeBridge;
use App\Services\Procurement\Process\ExecutionContextFactory;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Platforms\Contract\ContractRuntime;
use Tests\Support\Platforms\Contract\RecordingOutbound;
use Tests\Support\Platforms\Eis\EisListingFactsFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures as E;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ListingFixtures as F;

beforeEach(function (): void {
    E::authority([StageCode::Listing]);
    F::bindLocal();
});

test('Process archive split preserves overlapping inclusive cents and immutable filter bounds', function (): void {
    $partitioner = new EisListingPartitioner(new EisListingPolicy(minIntervalCents: 1));
    $filter = new EisListingFilter(['123']);
    $root = $partitioner->plan($filter, '2026-01-01', '2026-01-01', priceFrom: '0.01', priceTo: '0.03')->windows[0]->cursor;
    $split = $partitioner->split($root, 1);
    $children = array_map(EisListingCursor::decode(...), $split->children);
    expect([$children[0]->lowCents, $children[0]->highCents])->toBe([1, 2])
        ->and([$children[1]->lowCents, $children[1]->highCents])->toBe([2, 3])
        ->and($children[0]->filterHash->hex)->toBe($filter->fingerprint()->hex)
        ->and($partitioner->split($children[0]->envelope(), 3)->incompleteReason)->toBe(AssessmentReason::Overflow);
});

test('Process archive minimum interval cap retains explicit debt with no false coverage', function (): void {
    $run = F::run();
    $task = app(ListingStoreRepository::class)->task($run['input']);
    $partitioner = new EisListingPartitioner(new EisListingPolicy(minIntervalCents: 100000000001));
    $split = $partitioner->split($task->rootCursor, 1);
    expect($split->children)->toBe([]);
    $page = new ListingPage(new ProcurementSummaries([]), 500,
        new ResponseAssessment(Completeness::Partial, AssessmentReason::RepeatedPage, ContentHash::of('repeat')), TerminalProof::None, null);
    $lease = FinalizeFixtures::running($run['work']);
    app(WorkFinalizer::class)->finish($lease, new StageSucceeded(new ListingResult($task, $page, $split)));
    $coverage = app(ListingCoverageRepository::class)->read($run['stream']);
    expect($coverage->verifiedThrough)->toBeNull()->and($coverage->debt)->toHaveCount(1)
        ->and(DB::table('prc_process_runs')->value('discovery_outcome'))->toBe('incomplete')
        ->and(DB::table('prc_crawl_blocks')->whereNotNull('limitation_reason_code')->value('limitation_reason_code'))->toBe('listing_cap_unresolved')
        ->and(F::next($run['crawl']))->toBeNull();
});

test('Process archive publication failure rolls back the next page while retaining committed previous purchases', function (): void {
    $run = F::run();
    $task = app(ListingStoreRepository::class)->task($run['input']);
    $cursor = EisListingCursor::decode($task->cursor);
    $page = new ListingPage(new ProcurementSummaries([F::summary('first')]), 500,
        new ResponseAssessment(Completeness::Complete, AssessmentReason::Valid, ContentHash::of('first')), TerminalProof::None, $cursor->next(500)->envelope());
    F::finish($run['work'], $run['input'], $page);
    $next = F::next($run['crawl']);
    $task = app(ListingStoreRepository::class)->task($next['input']);
    $lease = FinalizeFixtures::running($next['work']);
    DB::unprepared("CREATE FUNCTION pg_temp.p218_archive_fail() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'archive journal fault'; END $$");
    DB::unprepared('CREATE TRIGGER p218_archive_fail BEFORE INSERT ON prc_process_transitions FOR EACH ROW EXECUTE FUNCTION pg_temp.p218_archive_fail()');
    try {
        expect(fn () => app(WorkFinalizer::class)->finish($lease, new StageSucceeded(new ListingResult($task, F::page([F::summary('second')])))))
            ->toThrow(QueryException::class, 'archive journal fault');
    } finally {
        DB::unprepared('DROP TRIGGER p218_archive_fail ON prc_process_transitions');
    }
    expect(DB::table('prc_procurements')->pluck('source_identity_value')->all())->toBe(['first'])
        ->and(DB::table('prc_crawl_blocks')->where('id', $next['input']->crawlBlockId->value)->value('terminal_proof'))->toBeNull()
        ->and(app(ListingStoreRepository::class)->task($next['input'])->cursor->serializedPayload)->toBe($task->cursor->serializedPayload)
        ->and(DB::table('prc_process_work')->where('id', $lease->workId->value->value)->value('state'))->toBe('running');
});

test('Process archive repeated page detection survives the persisted successor and drains both required split branches', function (): void {
    config(['platforms.eis.block_size' => 1]);
    $run = F::run('2026-03-01', blockSize: 1);
    $body = EisListingFactsFixtures::body([EisListingFactsFixtures::row('1')]);
    for ($i = 0; $i < 2; $i++) {
        $next = F::next($run['crawl']);
        // A new handler/outbound instance models delivery after the persisted first-page checkpoint.
        $outbound = new RecordingOutbound([new TransportSucceeded(
            ContractRuntime::response($body, 'text/csv'))]);
        $partitioner = new EisListingPartitioner;
        $handler = new ListingHandler(app(ListingStoreRepository::class),
            new EisListingSource(new EisPolicy(config()), $outbound,
                LedgerFixtures::uuid()), $partitioner, $partitioner, new FetchOutcomeBridge);
        $lease = FinalizeFixtures::running($next['work']);
        $context = app(ExecutionContextFactory::class)->open($lease);
        app(WorkFinalizer::class)->finish($lease, $handler->execute($next['input'], $context));
    }
    expect(DB::table('prc_crawl_blocks')->whereNotNull('parent_block_id')->where('partition_payload->page', 1)->count())->toBe(2)
        ->and(DB::table('prc_crawl_blocks')->whereNotNull('parent_block_id')->count())->toBe(3)
        ->and(DB::table('prc_process_runs')->value('discovery_outcome'))->toBe('collecting')
        ->and(DB::table('prc_procurements')->count())->toBe(1)
        ->and(DB::table('prc_process_work')->where('stage', 'detail')->count())->toBe(1);
    $n = 0;
    while (($next = F::next($run['crawl'])) !== null) {
        F::finish($next['work'], $next['input'], F::page());
        expect(++$n)->toBeLessThanOrEqual(4);
    }
    expect($n)->toBe(4)->and(DB::table('prc_process_runs')->value('discovery_outcome'))->toBe('complete');
});
