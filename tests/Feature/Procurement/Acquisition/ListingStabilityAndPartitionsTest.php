<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisListingCursor;
use App\Dto\Platforms\Eis\EisListingPolicy;
use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Dto\Procurement\Acquisition\DetailProgress;
use App\Dto\Procurement\Acquisition\DetailResult;
use App\Dto\Procurement\Acquisition\ListingFetched;
use App\Dto\Procurement\Acquisition\ListingPage;
use App\Dto\Procurement\Acquisition\ListingResult;
use App\Dto\Procurement\Acquisition\ProcurementSnapshot;
use App\Dto\Procurement\Acquisition\ProcurementSummaries;
use App\Dto\Procurement\Acquisition\SnapshotPart;
use App\Dto\Procurement\Details\AwardData;
use App\Dto\Procurement\Details\JournalEntryData;
use App\Dto\Procurement\Details\ProtocolData;
use App\Dto\Procurement\Details\SupplierResultsData;
use App\Dto\Procurement\Platform\CursorEnvelope;
use App\Dto\Procurement\Platform\ResponseAssessment;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\RetryAt;
use App\Dto\Procurement\Process\StageDeferred;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Enums\Procurement\AwardStatus;
use App\Enums\Procurement\Crawler\ListingSortField;
use App\Enums\Procurement\Platform\AssessmentReason;
use App\Enums\Procurement\Platform\Completeness;
use App\Enums\Procurement\Platform\SnapshotConsistency;
use App\Enums\Procurement\Platform\TerminalProof;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Enums\Procurement\Process\DeferralReason;
use App\Repositories\Procurement\Crawler\ListingCoverageRepository;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Services\Platforms\Eis\EisListingPartitioner;
use App\Services\Platforms\Eis\Listing\EisProfilePreparer;
use App\Services\Procurement\Acquisition\ListingHandler;
use App\Services\Procurement\Platform\Contracts\ListingSource;
use App\Services\Procurement\Platform\FetchOutcomeBridge;
use App\Services\Procurement\Process\ExecutionContextFactory;
use App\Services\Procurement\Process\WorkFinalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\AcquisitionProfileFixtures;
use Tests\Support\ProcessCore\DetailFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\ListingFixtures;

test('пауза перед отправкой сохраняет стабильность выдачи, ошибка обработчика её нарушает', function (bool $fault): void {
    ListingFixtures::bind();
    $run = ListingFixtures::run();
    $work = $run['work'];
    $input = $run['input'];
    $finalizer = app(WorkFinalizer::class);

    for ($pass = 1; $pass <= ($fault ? 3 : 2); $pass++) {
        $lease = FinalizeFixtures::running($work);
        if ($fault) {
            $finalizer->finishHandlerFault(lease: $lease, evidence: FinalizeFixtures::fault('listing'));
        } else {
            $finalizer->finish(lease: $lease, outcome: new StageDeferred(reason: DeferralReason::RateLimit,
                retryAt: new RetryAt(new DateTimeImmutable('+1 minute'))));
        }
        $lease = FinalizeFixtures::rerun($work);
        $task = app(ListingStoreRepository::class)->task($input);
        $result = new ListingResult(task: $task, page: ListingFixtures::page());
        expect($finalizer->finish(lease: $lease, outcome: new StageSucceeded($result))->status->value)->toBe('applied');
        $next = ListingFixtures::next($run['crawl']);
        if ($next === null) {
            break;
        }
        $work = $next['work'];
        $input = $next['input'];
    }

    expect($next)->toBeNull()
        ->and(DB::table('prc_process_runs')->where('id', $run['run']->value)->value('discovery_outcome'))->toBe($fault ? 'incomplete' : 'complete')
        ->and(app(ListingCoverageRepository::class)->read($run['stream'])->verifiedThrough)->toBe($fault ? null : '2026-01-01');
})->with(['обычная пауза' => false, 'ошибка обработчика' => true]);

test('обработчик переполнения учитывает уже выделенные корневые партиции', function (): void {
    ListingFixtures::bind();
    $run = ListingFixtures::run(through: '2026-01-02');
    $source = Mockery::mock(ListingSource::class);
    $source->expects('fetch')->andReturn(new ListingFetched(new ListingPage(
        summaries: new ProcurementSummaries([]), rowsTotal: 0,
        assessment: new ResponseAssessment(completeness: Completeness::Partial, reason: AssessmentReason::Overflow, bodyHash: ContentHash::of('overflow')),
        terminalProof: TerminalProof::None, nextCursor: null,
    )));
    $partitioner = new EisListingPartitioner(new EisListingPolicy(maxPartitions: 3));
    $handler = new ListingHandler(store: app(ListingStoreRepository::class), source: $source,
        partitioner: $partitioner, filters: $partitioner, bridge: new FetchOutcomeBridge);
    $lease = FinalizeFixtures::running($run['work']);
    $outcome = $handler->execute(input: $run['input'], execution: app(ExecutionContextFactory::class)->open($lease));
    if (! $outcome instanceof StageSucceeded || ! $outcome->result instanceof ListingResult) {
        throw new LogicException('Ожидался результат переполнения');
    }

    expect($outcome->result->split?->children)->toBe([])
        ->and($outcome->result->split?->incompleteReason)->toBe(AssessmentReason::Overflow);
});

test('свежий сбор сортировки по обновлению использует даты обновления и сохраняет явный from', function (): void {
    ListingFixtures::bind();
    $profile = AcquisitionProfileFixtures::create();
    $profile->update(['filter' => new EisSearchFilter(publishedFrom: CarbonImmutable::parse('2026-01-01T00:00:00Z'),
        okpd2Codes: ['43'], sortField: ListingSortField::Updated)]);
    $registry = $profile->registry ?? throw new LogicException('Ожидался реестр профиля');
    $preparer = app(EisProfilePreparer::class);
    $from = new DateTimeImmutable('2026-01-01T00:00:00Z');
    $through = new DateTimeImmutable('2026-01-10T00:00:00Z');
    $launch = $preparer->prepare(profile: $profile, kind: AcquisitionKind::Fresh, from: $from, through: $through);
    $cursor = EisListingCursor::decode($launch->plan->windows[0]->cursor);
    expect($cursor->dateField)->toBe('update')->and($cursor->highCents)->toBeNull();

    $run = ListingFixtures::run(from: '2026-01-08', stream: $launch->streamKey, registry: $registry);
    ListingFixtures::finish(work: $run['work'], input: $run['input'], page: ListingFixtures::page());
    $pass = ListingFixtures::next($run['crawl']) ?? throw new LogicException('Ожидался повторный проход');
    ListingFixtures::finish(work: $pass['work'], input: $pass['input'], page: ListingFixtures::page());

    $implicit = $preparer->prepare(profile: $profile, kind: AcquisitionKind::Fresh, through: $through);
    $explicit = $preparer->prepare(profile: $profile, kind: AcquisitionKind::Fresh, from: $from, through: $through);
    expect($implicit->plan->windows[0]->start)->toBe('2026-01-06')
        ->and($explicit->plan->windows[0]->start)->toBe('2026-01-01');
});

test('старый открытый курсор сохраняет поток, явный миллиард остаётся отдельной верхней границей', function (): void {
    $hash = ContentHash::of('scope');
    $finite = new EisListingCursor(day: '2026-01-01', dateField: 'update', lowCents: 0, highCents: 100_000_000_000, filterHash: $hash);
    $legacy = new CursorEnvelope(codecId: 'eis.listing', schemaVersion: 1, serializedPayload: $finite->envelope()->serializedPayload);
    $open = EisListingCursor::decode($legacy);
    $planner = new EisListingPartitioner;
    $access = ContentHash::of('access');
    $legacyStream = ContentHash::of(json_encode([1, $hash->hex, $access->hex, '1', 'update', 0, 100_000_000_000, 500], JSON_THROW_ON_ERROR));

    expect($open->highCents)->toBeNull()
        ->and(EisListingCursor::decode($open->next(500)->envelope())->highCents)->toBeNull()
        ->and(EisListingCursor::decode($finite->envelope())->highCents)->toBe(100_000_000_000)
        ->and($planner->streamKey(registryId: 1, cursor: $open->envelope(), accessPolicy: $access, adapterVersion: '1')->hex)->toBe($legacyStream->hex)
        ->and($planner->streamKey(registryId: 1, cursor: $finite->envelope(), accessPolicy: $access, adapterVersion: '1')->hex)->not->toBe($legacyStream->hex);

    [$left, $right] = array_map(EisListingCursor::decode(...), $planner->split(cursor: $open->envelope(), allocatedPartitions: 1)->children);
    expect($left->highCents)->toBe(100_000)->and($right->lowCents)->toBe(100_001)->and($right->highCents)->toBeNull();
});

test('исторические партиции журнала, протокола и награждения переживают откат публикации карточки', function (): void {
    DetailFixtures::bind();
    $fixture = DetailFixtures::work();
    $observedAt = new DateTimeImmutable('now');
    $parts = array_map(static fn (string $name): SnapshotPart => new SnapshotPart(name: $name,
        completeness: Completeness::Complete, required: true, bodyHash: ContentHash::of($name), fetchedAt: $observedAt), ['events', 'results']);
    $snapshot = new ProcurementSnapshot(identity: $fixture['request']->identity, title: 'Поставка бумаги', sourceStage: 'Подача заявок',
        parties: [], items: [], financing: [], documents: [], parts: $parts,
        events: [new JournalEntryData(occurredAt: Carbon::parse('2012-02-01T00:30:00+03:00'), rawText: 'Закупка переведена на этап «Подача заявок»')],
        results: new SupplierResultsData(
            protocol: new ProtocolData(type: 'итоговый', sourceNumber: '№1', signedAt: Carbon::parse('2012-02-05T00:00:00Z'), publishedAt: null),
            bids: [], award: new AwardData(winner: null, finalPrice: 1000.0, status: AwardStatus::Awarded, awardedAt: Carbon::parse('2012-03-10T00:00:00Z'))));
    $result = new DetailResult(snapshot: $snapshot, progress: new DetailProgress, consistency: SnapshotConsistency::BoundedRevalidated, observedAt: $observedAt);
    $lease = FinalizeFixtures::running($fixture['work']);
    $finalizer = app(WorkFinalizer::class);
    $events = DB::table('prc_events')->where('procurement_id', $fixture['p']->id)->where('occurred_at', '<', '2013-01-01');
    $partitions = ['prc_events_2012_01', 'prc_events_2012_02', 'prc_events_2012_03'];
    foreach ($partitions as $partition) {
        expect(DB::scalar('SELECT to_regclass(?)', [$partition]))->toBeNull();
    }

    DB::unprepared("CREATE FUNCTION pg_temp.reject_detail_transition() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'detail publication rollback'; END $$");
    DB::unprepared('CREATE TRIGGER reject_detail_transition BEFORE INSERT ON prc_process_transitions FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_detail_transition()');
    try {
        expect(fn () => $finalizer->complete(lease: $lease, result: $result))->toThrow(QueryException::class, 'detail publication rollback');
        expect($events->count())->toBe(0);
        foreach ($partitions as $partition) {
            expect(DB::scalar('SELECT to_regclass(?)', [$partition]))->toBe($partition);
        }
    } finally {
        DB::unprepared('DROP TRIGGER reject_detail_transition ON prc_process_transitions');
    }

    expect($finalizer->complete(lease: $lease, result: $result)->status->value)->toBe('applied')
        ->and($events->orderBy('occurred_at')->pluck('event_type')->all())->toBe(['status_changed', 'protocol_published', 'award_made'])
        ->and($events->pluck('occurred_at')->all())->toBe(['2012-01-31 21:30:00+00', '2012-02-05 00:00:00+00', '2012-03-10 00:00:00+00']);
});
