<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Platforms\Eis\EisSearchFilter;
use App\Dto\Procurement\Network\Outcome\TransportDeferred;
use App\Dto\Procurement\Network\Outcome\TransportSucceeded;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Inputs\WorkInput;
use App\Dto\Procurement\Process\Outcomes\StageOutcome;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\RetryAt;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Process\Core\AcquisitionKind;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Exceptions\Procurement\Process\SliceExhausted;
use App\Jobs\Procurement\Process\ExecuteWorkJob;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\Crawler\ListingCoverageRepository;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Services\Platforms\Eis\EisListingPartitioner;
use App\Services\Platforms\Eis\EisListingSource;
use App\Services\Procurement\Acquisition\Listing\AcquisitionProfiles;
use App\Services\Procurement\Acquisition\Listing\ListingHandler;
use App\Services\Procurement\Acquisition\Orchestration\AcquisitionScheduler;
use App\Services\Procurement\Platform\FetchOutcomeBridge;
use App\Services\Procurement\Process\Contracts\StageHandler;
use App\Services\Procurement\Process\Execution\StageQueueMap;
use App\Services\Procurement\Process\Execution\StageRegistry;
use App\Services\Procurement\Process\Execution\WorkFeeder;
use App\Services\Procurement\Process\Execution\WorkRunner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Platforms\Contract\ContractRuntime;
use Tests\Support\Platforms\Contract\RecordingOutbound;
use Tests\Support\ProcessCore\AcquisitionProfileFixtures as P;
use Tests\Support\ProcessCore\ExecutionFixtures as E;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FixtureStageCheckpoint;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ListingFixtures as F;

beforeEach(fn () => E::authority([StageCode::Listing]));

function bindListingDelivery(RecordingOutbound $outbound): void
{
    F::bindLocal();
    $partitioner = new EisListingPartitioner;
    app(StageRegistry::class)->bindHandler(StageCode::Listing, new ListingHandler(
        app(ListingStoreRepository::class), new EisListingSource(new EisPolicy(config()), $outbound, LedgerFixtures::uuid()),
        $partitioner, $partitioner, new FetchOutcomeBridge));
}

function listingDelivery(WorkId $work): ExecuteWorkJob
{
    app(WorkFeeder::class)->tick('acquisition');

    return new ExecuteWorkJob($work->value->value, E::delivery($work)->value, LedgerFixtures::uuid()->value);
}

function emptyListingTransport(): TransportSucceeded
{
    return new TransportSucceeded(ContractRuntime::response(file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Csv/neutral-empty.csv')), 'text/csv'));
}

function deliverTwoFullPasses(): void
{
    Queue::fake();
    $f = F::run();
    $outbound = new RecordingOutbound([emptyListingTransport(), emptyListingTransport()]);
    bindListingDelivery($outbound);
    listingDelivery($f['work'])->handle(app(WorkRunner::class));
    $next = F::next($f['crawl']) ?? throw new LogicException('Missing full second pass');
    expect(E::work($f['work'])->state)->toBe('succeeded')
        ->and(DB::table('prc_process_runs')->where('id', $f['run']->value)->value('discovery_outcome'))->toBe('collecting');
    listingDelivery($next['work'])->handle(app(WorkRunner::class));
    expect(F::next($f['crawl']))->toBeNull()->and($outbound->plans)->toHaveCount(2)
        ->and(DB::table('prc_process_runs')->where('id', $f['run']->value)->value('discovery_outcome'))->toBe('complete')
        ->and(DB::table('prc_process_run_work')->where('accounting_role', 'required')->count())->toBe(2);
    Queue::assertNothingPushed();
}

test('Process listing delivery uses the registered handler and finishes only after stable full passes', function (): void {
    deliverTwoFullPasses();
});

test('Process duplicate delivery cannot execute its parser or publish a second successor', function (): void {
    $f = F::run();
    $outbound = new RecordingOutbound([emptyListingTransport()]);
    bindListingDelivery($outbound);
    $job = listingDelivery($f['work']);
    $job->handle(app(WorkRunner::class));
    $before = DB::table('prc_process_work')->count();
    $job->handle(app(WorkRunner::class));
    expect($outbound->plans)->toHaveCount(1)->and(DB::table('prc_process_work')->count())->toBe($before)
        ->and(DB::table('prc_process_transitions')->where('work_id', $f['work']->value->value)->where('new_state', 'succeeded')->count())->toBe(1);
});

test('different Process work identities execute independently without a profile wide Redis lock', function (): void {
    $one = F::run();
    $two = F::run(registry: Registry::query()->sole());
    $outbound = new RecordingOutbound([emptyListingTransport(), emptyListingTransport()]);
    bindListingDelivery($outbound);
    listingDelivery($one['work'])->handle(app(WorkRunner::class));
    listingDelivery($two['work'])->handle(app(WorkRunner::class));
    expect($one['work']->value->value)->not->toBe($two['work']->value->value)
        ->and(E::work($one['work'])->state)->toBe('succeeded')->and(E::work($two['work'])->state)->toBe('succeeded')
        ->and($outbound->plans)->toHaveCount(2);
});

test('Process listing queue route and timeout belong to the stage with no unique lock TTL', function (): void {
    $route = app(StageQueueMap::class)->forStage(StageCode::Listing);
    $job = new ExecuteWorkJob(LedgerFixtures::uuid()->value, 1, LedgerFixtures::uuid()->value);
    expect($route->connection)->toBe('process_long')->and($route->queue)->toBe('process-listing')
        ->and($route->sliceSeconds)->toBeLessThan($route->jobTimeout)
        ->and($route->horizonTimeout)->toBeLessThan($route->retryAfter)
        ->and($route->retryAfter)->toBeLessThan($route->leaseSeconds)
        ->and($job)->not->toBeInstanceOf(ShouldBeUnique::class)
        ->and(is_file(app_path('Jobs/Procurement/CrawlRunJob.php')))->toBeFalse();
});

test('Process slice exhaustion commits progress releases admission and resumes without self dispatch', function (): void {
    Queue::fake();
    FinalizeFixtures::authority([StageCode::Listing]);
    $handler = new class implements StageHandler
    {
        public int $calls = 0;

        public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome
        {
            if (++$this->calls === 1) {
                $execution->checkpoint(new FixtureStageCheckpoint(501));
                throw SliceExhausted::forWork($execution->lease()->workId->value->value);
            }

            return FinalizeFixtures::succeeded(StageCode::Listing, 'continued');
        }
    };
    app(StageRegistry::class)->bindHandler(StageCode::Listing, $handler);
    $work = E::listingWork();
    listingDelivery($work)->handle(app(WorkRunner::class));
    expect(E::work($work)->state)->toBe('ready')->and(E::work($work)->owner_token)->toBeNull()
        ->and(E::work($work)->item_failures)->toBe(0)->and(E::work($work)->checkpoint)->not->toBeNull()
        ->and(DB::table('prc_admission_groups')->where('state', '<>', 'released')->count())->toBe(0);
    listingDelivery($work)->handle(app(WorkRunner::class));
    expect(E::work($work)->state)->toBe('succeeded')->and($handler->calls)->toBe(2)
        ->and(DB::table('prc_process_work')->count())->toBe(1);
    Queue::assertNothingPushed();
});

test('Process resource debt remains explicit without a busy continuation dispatch loop', function (): void {
    Queue::fake();
    $f = F::run('2026-01-01', '2026-01-03', budget: 1);
    $outbound = new RecordingOutbound([emptyListingTransport(), emptyListingTransport()]);
    bindListingDelivery($outbound);
    listingDelivery($f['work'])->handle(app(WorkRunner::class));
    $next = F::next($f['crawl']) ?? throw new LogicException('Missing second pass');
    listingDelivery($next['work'])->handle(app(WorkRunner::class));
    $coverage = app(ListingCoverageRepository::class)->read($f['stream']);
    expect($coverage->debt)->toHaveCount(1)->and($coverage->debt[0]->windowStart)->toBe('2026-01-02')
        ->and(DB::table('prc_process_runs')->where('id', $f['run']->value)->value('discovery_outcome'))->toBe('incomplete')
        ->and(app(WorkFeeder::class)->tick('acquisition'))->toBe(0);
    Queue::assertNothingPushed();
});

test('Process archive continuation preserves the original typed filter and route after profile edits', function (): void {
    $profile = P::create(AcquisitionKind::Archive);
    $profile->update(['filter' => new EisSearchFilter(stageCompleted: true, okpd2Codes: ['43'], priceFrom: 1, priceTo: 100)]);
    $launch = app(AcquisitionProfiles::class)->prepare($profile, AcquisitionKind::Archive,
        new DateTimeImmutable('2025-01-01'), new DateTimeImmutable('2025-01-01'));
    $run = app(AcquisitionScheduler::class)->manual($launch, LedgerFixtures::uuid(), null);
    $row = DB::table('prc_process_work')->where('origin_run_id', $run->value)->sole();
    $work = new WorkId(new ProcessUuid($row->id));
    $body = str_replace(['Подача заявок', '31.10.2026', '1250000,50'], ['Закупка завершена', '01.01.2025', '50,00'],
        file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Csv/neutral-valid.csv')));
    $outbound = new RecordingOutbound([
        new TransportDeferred(NetworkDeferralReason::RateLimit, new RetryAt(new DateTimeImmutable('+1 hour'))),
        new TransportSucceeded(ContractRuntime::response($body, 'text/csv')),
        new TransportSucceeded(ContractRuntime::response($body, 'text/csv')),
        new TransportSucceeded(ContractRuntime::response($body, 'text/csv')),
    ]);
    bindListingDelivery($outbound);
    listingDelivery($work)->handle(app(WorkRunner::class));
    expect(E::work($work)->state)->toBe('deferred');
    $profile->update(['filter' => new EisSearchFilter(stageApplications: true, okpd2Codes: ['99'], priceTo: 5000)]);
    DB::table('prc_process_work')->where('id', $work->value->value)->update(['due_at' => DB::raw("clock_timestamp()-interval '1 second'")]);
    listingDelivery($work)->handle(app(WorkRunner::class));
    $crawl = new ProcessUuid(DB::table('prc_crawl_runs')->where('process_run_id', $run->value)->sole()->id);
    $pass = F::next($crawl) ?? throw new LogicException('Missing stable pass');
    listingDelivery($pass['work'])->handle(app(WorkRunner::class));
    // A retried pass is best-observed; two later uninterrupted full passes prove stability.
    expect(DB::table('prc_process_runs')->where('id', $run->value)->value('discovery_outcome'))->toBe('collecting');
    $stable = F::next($crawl) ?? throw new LogicException('Missing uninterrupted closing pass');
    listingDelivery($stable['work'])->handle(app(WorkRunner::class));
    expect($outbound->plans)->toHaveCount(4)->and(Procurement::query()->where('source_identity_value', '00042')->exists())->toBeTrue()
        ->and(DB::table('prc_process_runs')->where('id', $run->value)->value('discovery_outcome'))->toBe('complete');
    foreach ($outbound->plans as $plan) {
        parse_str((string) parse_url($plan->url->reveal(), PHP_URL_QUERY), $query);
        expect($query)->toMatchArray(['pc' => 'on', 'okpd2Ids' => '123', 'priceFromGeneral' => '1.00',
            'priceToGeneral' => '100.00', 'publishDateFrom' => '01.01.2025', 'publishDateTo' => '01.01.2025'])
            ->not->toHaveKey('af');
    }
});
