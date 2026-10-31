<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisListingCursor;
use App\Dto\Platforms\Eis\EisListingFilter;
use App\Dto\Platforms\Eis\EisListingPolicy;
use App\Dto\Procurement\Acquisition\ListingCoverage;
use App\Dto\Procurement\Acquisition\ListingPage;
use App\Dto\Procurement\Acquisition\ListingResult;
use App\Dto\Procurement\Acquisition\ProcurementSummaries;
use App\Dto\Procurement\Platform\ResponseAssessment;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Enums\Procurement\Platform\AssessmentReason;
use App\Enums\Procurement\Platform\Completeness;
use App\Enums\Procurement\Platform\TerminalProof;
use App\Models\Procurement\Platform\Registry;
use App\Repositories\Procurement\Crawler\ListingCoverageRepository;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Services\Platforms\Eis\EisListingPartitioner;
use App\Services\Procurement\Process\WorkFinalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\ListingFixtures as F;

pest()->group('t07', 't27', 'p23');

beforeEach(fn () => F::bind());

test('T27 decimal overlap, inclusive rows and bounded root continuation preserve declared scope', function (): void {
    $planner = new EisListingPartitioner;
    $filter = new EisListingFilter(['123'], ['af']);
    $plan = $planner->plan($filter, '2024-01-01', '2026-01-01', 'update', '0.01', '2500001.00');
    expect($plan->windows)->toHaveCount(400)->and($plan->continuation)->not->toBeNull()
        ->and($plan->continuation?->end)->toBe('2026-01-01');
    $cursor = EisListingCursor::decode($plan->windows[0]->cursor);
    expect($cursor->firstRow)->toBe(1)->and($cursor->next(500)->firstRow)->toBe(501)
        ->and($cursor->next(500)->next(500)->firstRow)->toBe(1001);
    $split = $planner->split($cursor->envelope(), 1);
    $left = EisListingCursor::decode($split->children[0]);
    $right = EisListingCursor::decode($split->children[1]);
    expect($left->highCents)->toBe($right->lowCents)->and($left->highCents)->toBe(125000050)
        ->and($right->highCents)->toBe(250000100)
        ->and($planner->restoreFilter($planner->snapshot($filter))->fingerprint()->hex)->toBe($filter->fingerprint()->hex);
    expect((new EisListingPartitioner(new EisListingPolicy(maxPartitions: 2)))->split($cursor->envelope(), 1)->incompleteReason)->toBe(AssessmentReason::Overflow);
    expect(fn () => $planner->plan($filter, '2026-01-01', '2026-01-01', priceTo: '0'))->toThrow(InvalidArgumentException::class);
});

test('T27 same identities changed content require a third full pass and required detail edges', function (): void {
    $run = F::run();
    F::finish($run['work'], $run['input'], F::page([F::summary('00042', 'A')]));
    expect(DB::table('prc_process_runs')->where('id', $run['run']->value)->value('discovery_outcome'))->toBe('collecting');
    $next = F::next($run['crawl']) ?? throw new LogicException('Missing successor');
    F::finish($next['work'], $next['input'], F::page([F::summary('00042', 'B')]));
    expect(F::next($run['crawl']))->not->toBeNull();
    $next = F::next($run['crawl']) ?? throw new LogicException('Missing successor');
    F::finish($next['work'], $next['input'], F::page([F::summary('00042', 'B')]));
    expect(F::next($run['crawl']))->toBeNull()
        ->and(DB::table('prc_process_runs')->where('id', $run['run']->value)->value('discovery_outcome'))->toBe('complete')
        ->and(DB::table('prc_procurements')->count())->toBe(1)
        ->and(DB::table('prc_procurements')->value('title'))->toBe('B')
        ->and(DB::table('prc_process_run_work')->where('run_id', $run['run']->value)->where('accounting_role', 'required')->count())->toBe(5);
    $coverage = app(ListingCoverageRepository::class)->read($run['stream']);
    expect($coverage->verifiedThrough)->toBe('2026-01-01')->and($coverage->debt)->toBe([])
        ->and($coverage->resumeFrom('2025-01-01'))->toBe('2025-12-30');
});

test('T27 deletion before offset restarts at first row and cannot certify an incomplete pass', function (): void {
    $run = F::run();
    $task = app(ListingStoreRepository::class)->task($run['input']);
    $cursor = EisListingCursor::decode($task->cursor);
    $page = new ListingPage(new ProcurementSummaries([F::summary('A'), F::summary('B')]), 500,
        new ResponseAssessment(Completeness::Complete, AssessmentReason::Valid, ContentHash::of('page')),
        TerminalProof::None, $cursor->next(500)->envelope());
    F::finish($run['work'], $run['input'], $page);
    $next = F::next($run['crawl']) ?? throw new LogicException('Missing successor');
    F::finish($next['work'], $next['input'], F::page([F::summary('D')])); // C shifted before offset after deletion.
    $next = F::next($run['crawl']) ?? throw new LogicException('Missing successor');
    expect(EisListingCursor::decode(app(ListingStoreRepository::class)->task($next['input'])->cursor)->firstRow)->toBe(1);
    F::finish($next['work'], $next['input'], F::page([F::summary('B'), F::summary('C'), F::summary('D')]));
    $next = F::next($run['crawl']) ?? throw new LogicException('Missing successor');
    expect($next)->not->toBeNull();
    F::finish($next['work'], $next['input'], F::page([F::summary('D'), F::summary('C'), F::summary('B')]));
    expect(app(ListingCoverageRepository::class)->read($run['stream'])->verifiedThrough)->toBe('2026-01-01')
        ->and(DB::table('prc_procurements')->where('source_identity_value', 'C')->exists())->toBeTrue();
});

test('T27 capped split parent does not close discovery and every descendant is required', function (): void {
    $run = F::run(budget: 3);
    $task = app(ListingStoreRepository::class)->task($run['input']);
    $page = new ListingPage(new ProcurementSummaries([]), 5000,
        new ResponseAssessment(Completeness::Partial, AssessmentReason::Overflow, ContentHash::of('cap')), TerminalProof::None, null);
    $lease = FinalizeFixtures::running($run['work']);
    app(WorkFinalizer::class)->finish($lease, new StageSucceeded(new ListingResult($task, $page, (new EisListingPartitioner)->split($task->rootCursor, 1))));
    expect(DB::table('prc_process_runs')->where('id', $run['run']->value)->value('discovery_outcome'))->toBe('collecting')
        ->and(DB::table('prc_process_run_work')->where('run_id', $run['run']->value)->count())->toBe(3);
    $child = F::next($run['crawl']) ?? throw new LogicException('Missing successor');
    $task = app(ListingStoreRepository::class)->task($child['input']);
    $lease = FinalizeFixtures::running($child['work']);
    app(WorkFinalizer::class)->finish($lease, new StageSucceeded(new ListingResult($task, $page, (new EisListingPartitioner)->split($task->rootCursor, 1))));
    while (($child = F::next($run['crawl'])) !== null) {
        F::finish($child['work'], $child['input'], F::page());
    }
    expect(DB::table('prc_process_runs')->where('id', $run['run']->value)->value('discovery_outcome'))->toBe('incomplete')
        ->and(DB::table('prc_crawl_blocks')->where('limitation_reason_code', 'listing_cap_unresolved')->count())->toBe(1)
        ->and(app(ListingCoverageRepository::class)->read($run['stream'])->verifiedThrough)->toBeNull();
});

test('T27 open boundary retains closing debt beyond fresh horizon with immutable recovery filter', function (): void {
    $day = (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))->modify('-2 days')->format('Y-m-d');
    $run = F::run($day);
    F::finish($run['work'], $run['input'], F::page());
    $coverage = app(ListingCoverageRepository::class)->read($run['stream']);
    expect($coverage->verifiedThrough)->toBeNull()->and($coverage->observedThrough)->toBe($day)
        ->and($coverage->debt)->toHaveCount(1)
        ->and(app(ListingCoverageRepository::class)->due($run['stream'], new DateTimeImmutable('+90 days')))->toHaveCount(1)
        ->and($coverage->debt[0]->filter->serializedPayload)->toBe('{"ids":["123"],"stages":[],"search":null,"morphology":false,"sortBy":"UPDATE_DATE"}');
});

test('T07 stale owner and mandatory journal failure leave zero summaries cursor successors and receipts', function (): void {
    $run = F::run();
    $lease = FinalizeFixtures::running($run['work']);
    $result = new StageSucceeded(new ListingResult(app(ListingStoreRepository::class)->task($run['input']), F::page([F::summary('X')])));
    DB::unprepared("CREATE FUNCTION pg_temp.p215_fail() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'p215 journal failure'; END $$");
    DB::unprepared('CREATE TRIGGER p215_fail BEFORE INSERT ON prc_process_transitions FOR EACH ROW EXECUTE FUNCTION pg_temp.p215_fail()');
    try {
        expect(fn () => app(WorkFinalizer::class)->finish($lease, $result))->toThrow(QueryException::class, 'p215 journal failure');
        expect(DB::table('prc_procurements')->count())->toBe(0)->and(DB::table('prc_crawl_blocks')->whereNotNull('terminal_proof')->count())->toBe(0)
            ->and(DB::table('prc_process_work')->count())->toBe(1);
    } finally {
        DB::unprepared('DROP TRIGGER p215_fail ON prc_process_transitions');
    }
    FinalizeFixtures::revoke($run['work'], true);
    expect(app(WorkFinalizer::class)->finish($lease, $result)->status->value)->toBe('lost_ownership')
        ->and(DB::table('prc_procurements')->count())->toBe(0)->and(DB::table('prc_crawl_blocks')->whereNotNull('terminal_proof')->count())->toBe(0);
});

test('T27 interrupted offset pass is best observed and requires two new complete passes', function (): void {
    $run = F::run();
    $lease = FinalizeFixtures::running($run['work']);
    FinalizeFixtures::expire($run['work']);
    expect(FinalizeFixtures::revoke($run['work']))->toBeTrue();
    F::finish($run['work'], $run['input'], F::page());
    $next = F::next($run['crawl']) ?? throw new LogicException('Missing full repeat');
    F::finish($next['work'], $next['input'], F::page());
    expect(DB::table('prc_process_runs')->where('id', $run['run']->value)->value('discovery_outcome'))->toBe('collecting');
    $next = F::next($run['crawl']) ?? throw new LogicException('Missing stability pass');
    F::finish($next['work'], $next['input'], F::page());
    expect(app(ListingCoverageRepository::class)->read($run['stream'])->verifiedThrough)->toBe('2026-01-01');
});

test('T27 three changing full passes preserve unstable debt and never advance verified watermark', function (): void {
    $run = F::run();
    F::finish($run['work'], $run['input'], F::page([F::summary('same', 'A')]));
    foreach (['B', 'C'] as $value) {
        $next = F::next($run['crawl']) ?? throw new LogicException('Missing pass');
        F::finish($next['work'], $next['input'], F::page([F::summary('same', $value)]));
    }
    expect(DB::table('prc_crawl_blocks')->where('limitation_reason_code', 'unstable_source')->count())->toBe(1)
        ->and(DB::table('prc_process_runs')->where('id', $run['run']->value)->value('discovery_outcome'))->toBe('incomplete')
        ->and(app(ListingCoverageRepository::class)->read($run['stream'])->verifiedThrough)->toBeNull();
});

test('T27 publish and update streams differ and immutable recovery preserves decimal profile bounds', function (): void {
    $planner = new EisListingPartitioner;
    $filter = new EisListingFilter(['123'], ['pc']);
    $publish = $planner->plan($filter, '2026-01-01', '2026-01-01', 'publish', '42.01', '2000000.50');
    $update = $planner->plan($filter, '2026-01-01', '2026-01-01', 'update', '42.01', '2000000.50');
    $access = ContentHash::of('access');
    expect($planner->streamKey(1, $publish->windows[0]->cursor, $access, 'v1')->hex)
        ->not->toBe($planner->streamKey(1, $update->windows[0]->cursor, $access, 'v1')->hex);
    $later = $planner->plan($filter, '2026-10-31', '2026-10-31', 'update', '42.01', '2000000.50');
    expect($planner->streamKey(1, $update->windows[0]->cursor, $access, 'v1')->hex)
        ->toBe($planner->streamKey(1, $later->windows[0]->cursor, $access, 'v1')->hex);
    $run = F::run();
    $task = app(ListingStoreRepository::class)->task($run['input']);
    expect($planner->recoveryPlan($task)->windows[0]->cursor->serializedPayload)->toBe($task->rootCursor->serializedPayload);
    $coverage = new ListingCoverage('2026-01-01', null, []);
    expect($coverage->resumeFrom('2025-01-01'))->toBe('2025-12-30'); // still January after a many-month outage
});

test('T27 due closing reconciliation replaces observed debt with complete coverage using the saved scope', function (): void {
    $closing = (new DateTimeImmutable('+3 seconds', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    $run = F::run(closesAt: $closing);
    F::finish($run['work'], $run['input'], F::page());
    $coverage = app(ListingCoverageRepository::class);
    expect($coverage->read($run['stream'])->verifiedThrough)->toBeNull();
    $deadline = hrtime(true) + 5_000_000_000;
    while (new DateTimeImmutable($closing) > new DateTimeImmutable('now')) {
        if (hrtime(true) > $deadline) {
            throw new LogicException('Closing deadline exceeded');
        }
        usleep(100_000);
    }
    $saved = $coverage->due($run['stream'], new DateTimeImmutable('now'))[0];
    $registry = Registry::query()->findOrFail($saved->registryId);
    $recovery = F::run($saved->windowStart, $saved->windowEnd, stream: $run['stream'], registry: $registry, closesAt: $saved->closesAt);
    F::finish($recovery['work'], $recovery['input'], F::page());
    $next = F::next($recovery['crawl']) ?? throw new LogicException('Missing closing pass');
    F::finish($next['work'], $next['input'], F::page());
    expect($coverage->read($run['stream'])->verifiedThrough)->toBe('2026-01-01')
        ->and($coverage->due($run['stream'], new DateTimeImmutable('now')))->toBe([]);
});

test('T27 root budget keeps an immutable continuation debt instead of discarding the tail', function (): void {
    $run = F::run('2026-01-01', '2026-01-03', budget: 1);
    F::finish($run['work'], $run['input'], F::page());
    $next = F::next($run['crawl']) ?? throw new LogicException('Missing pass');
    F::finish($next['work'], $next['input'], F::page());
    $coverage = app(ListingCoverageRepository::class)->read($run['stream']);
    expect($coverage->verifiedThrough)->toBe('2026-01-01')->and($coverage->debt)->toHaveCount(1)
        ->and($coverage->debt[0]->windowStart)->toBe('2026-01-02')->and($coverage->debt[0]->windowEnd)->toBe('2026-01-03')
        ->and(DB::table('prc_process_runs')->where('id', $run['run']->value)->value('discovery_outcome'))->toBe('incomplete');
});
