<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisListingFilter;
use App\Dto\Procurement\Acquisition\AcquisitionLaunch;
use App\Dto\Procurement\Acquisition\DetailProgress;
use App\Dto\Procurement\Acquisition\DetailResult;
use App\Dto\Procurement\Acquisition\ProcurementSnapshot;
use App\Dto\Procurement\Document\DocumentReference;
use App\Dto\Procurement\Document\SourceDocumentKey;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Process\ArtifactRef;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\DetailInput;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\StageFailedItem;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Platform\SnapshotConsistency;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\DiscoveryOutcome;
use App\Enums\Procurement\Process\ItemFailureReason;
use App\Enums\Procurement\Process\ProcessRunState;
use App\Enums\Procurement\Process\StageCode;
use App\Filament\Resources\Procurement\Process\Runs\ProcessRunResource;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Registry;
use App\Models\User;
use App\Repositories\Procurement\Crawler\DetailSnapshotRepository;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Repositories\Procurement\Crawler\RefreshRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Platforms\Eis\EisListingPartitioner;
use App\Services\Procurement\Acquisition\AcquisitionScheduler;
use App\Services\Procurement\Process\ProcessOperatorCommands;
use App\Services\Procurement\Process\ProcessPolicy;
use App\Services\Procurement\Process\RunProjector;
use App\Services\Procurement\Process\WorkFeeder;
use App\Services\Procurement\Process\WorkFinalizer;
use Database\Factories\Platforms\Eis\EisCrawlerFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ProcessCore\DetailFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\ListingFixtures;

use function Pest\Laravel\actingAs;

pest()->group('acquisition-operations', 'run-state-reduction', 'run-lifecycle');

// acceptance: run lifecycle и независимость площадок через настоящий AcquisitionScheduler/ListingFinalizer/DetailFinalizer.

beforeEach(function (): void {
    ExecutionFixtures::authority([StageCode::Listing, StageCode::Detail, StageCode::StatusRefresh]);
    ListingFixtures::bindLocal();
    DetailFixtures::bindLocal();
});

function lifecycleLaunch(Crawler $profile): AcquisitionLaunch
{
    $partitioner = new EisListingPartitioner;
    $filter = new EisListingFilter(['123']);

    return new AcquisitionLaunch($profile->id, 1, $profile->registry->platform_id, $profile->registry_id,
        $profile->registry->code, AcquisitionKind::Fresh, 'fixture-listing/1', $partitioner->snapshot($filter),
        ContentHash::of('access'), ContentHash::of('stream:fresh'),
        $partitioner->plan($filter, '2026-01-01', '2026-01-01'),
        new VersionedPayload('{"profile_version":1}', 1));
}

function lifecycleCrawl(ProcessUuid $run): ProcessUuid
{
    return new ProcessUuid(DB::table('prc_crawl_runs')->where('process_run_id', $run->value)->sole()->id);
}

/** Завершает все готовые listing pages crawl run одной и той же страницей (две стабильные passes закрывают discovery). */
function lifecycleDrainListing(ProcessUuid $run, array $summaries): void
{
    $crawl = lifecycleCrawl($run);
    for ($guard = 0; ($next = ListingFixtures::next($crawl)) !== null; $guard++) {
        if ($guard > 4) {
            throw new LogicException('Listing graph did not converge.');
        }
        ListingFixtures::finish($next['work'], $next['input'], ListingFixtures::page($summaries));
    }
}

test('actual producer: две стабильные passes закрывают discovery, detail публикует document handoff, pending document intent не удерживает run', function (): void {
    Queue::fake();
    $profile = EisCrawlerFactory::new()->create();
    $run = app(AcquisitionScheduler::class)->manual(lifecycleLaunch($profile), ListingStoreRepository::id('lifecycle-complete'), null);
    expect(app(RunProjector::class)->summary($run)?->discovery)->toBe(DiscoveryOutcome::Collecting);
    lifecycleDrainListing($run, [ListingFixtures::summary('lifecycle-source')]);
    expect(DB::table('prc_process_runs')->where('id', $run->value)->value('discovery_outcome'))->toBe('complete')
        ->and(app(RunProjector::class)->summary($run)?->state)->toBe(ProcessRunState::Running);

    $detail = DB::table('prc_process_work')->where('stage', 'detail')->sole();
    $work = new WorkId(new ProcessUuid($detail->id));
    $input = DetailFixtures::input($work);
    expect($input)->toBeInstanceOf(DetailInput::class);
    /** @var DetailInput $input */
    $request = app(DetailSnapshotRepository::class)->request($input->procurementId, $input->intentId);
    $part = DetailFixtures::snapshot($request, 'documents');
    $reference = new DocumentReference($request->identity, 'attachment', 'Estimate', new SafeUrl('https://example.org/estimate?token=one'), new SourceDocumentKey('attachment:1', 1));
    $snapshot = new ProcurementSnapshot($part->identity, $part->title, $part->sourceStage, [], [], [], [$reference], $part->parts);
    app(WorkFinalizer::class)->finish(FinalizeFixtures::running($work),
        new StageSucceeded(new DetailResult($snapshot, new DetailProgress, SnapshotConsistency::BoundedRevalidated, new DateTimeImmutable)));

    $summary = app(RunProjector::class)->summary($run);
    expect($summary?->state)->toBe(ProcessRunState::Completed)
        ->and($summary?->discovery)->toBe(DiscoveryOutcome::Complete)
        ->and($summary?->required->succeeded)->toBe(3)
        ->and($summary?->required->terminal())->toBe(3)
        ->and($summary?->observes)->toBe(0)
        ->and(DB::table('prc_operation_intents')->where('stage', 'download')->value('state'))->toBe('pending')
        ->and(DB::table('prc_process_work')->where('stage', 'download')->count())->toBe(0);
    Queue::assertNothingPushed();
});

/** Feeder admission detail lane и claim первой admitted detail work; supersede при admission/claim создаёт successor. */
function lifecycleClaimNextDetail(): ?ExecutionLease
{
    for ($round = 0; $round < 4; $round++) {
        app(WorkFeeder::class)->tick(app(ProcessPolicy::class)->parentLane(StageCode::Detail));
        foreach (DB::table('prc_process_work')->where('stage', 'detail')->where('state', 'admitted')->orderBy('created_at')->pluck('id') as $id) {
            $work = new WorkId(new ProcessUuid($id));
            $claim = app(WorkStoreRepository::class)->claim($work, ExecutionFixtures::delivery($work));
            if ($claim->lease !== null) {
                return $claim->lease;
            }
        }
    }

    return null;
}

function lifecycleFinishDetail(ExecutionLease $lease, string $body): void
{
    $input = DetailFixtures::input($lease->workId);
    if (! $input instanceof DetailInput) {
        throw new LogicException('Detail input required.');
    }
    $request = app(DetailSnapshotRepository::class)->request($input->procurementId, $input->intentId);
    $completion = app(WorkFinalizer::class)->finish($lease, new StageSucceeded(new DetailResult(
        DetailFixtures::snapshot($request, 'attributes', body: $body), new DetailProgress, SnapshotConsistency::BoundedRevalidated, new DateTimeImmutable)));
    expect($completion->status->value)->toBe('applied');
}

/** @return list<object> */
function lifecycleDetailIntents(int $procurement, string $state): array
{
    return DB::table('prc_operation_intents')->where('stage', 'detail')->where('subject_id', (string) $procurement)
        ->where('state', $state)->orderBy('requested_at')->get()->all();
}

test('actual producer: superseded required detail заменяется successor для всех нуждающихся runs, pending intent исполняется после active', function (): void {
    $profile = EisCrawlerFactory::new()->create();
    $scheduler = app(AcquisitionScheduler::class);
    $launch = lifecycleLaunch($profile);
    $runs = [];
    // Второй run видит изменённое содержимое той же закупки: head растёт, прежняя detail input устаревает.
    foreach (['A' => 'lifecycle-supersede-a', 'B' => 'lifecycle-supersede-b'] as $content => $command) {
        $run = $scheduler->manual($launch, ListingStoreRepository::id($command), null);
        $runs[$content] = $run;
        lifecycleDrainListing($run, [ListingFixtures::summary('lifecycle-shared', $content)]);
    }
    $procurement = (int) DB::table('prc_procurements')->value('id');
    expect(DB::table('prc_procurements')->value('head_generation'))->toBe(2)
        ->and(lifecycleDetailIntents($procurement, 'active'))->toHaveCount(1)
        ->and(lifecycleDetailIntents($procurement, 'pending'))->toHaveCount(1);
    [$first] = lifecycleDetailIntents($procurement, 'active');
    [$queued] = lifecycleDetailIntents($procurement, 'pending');
    $stale = DB::table('prc_process_work')->where('intent_id', $first->id)->sole();
    $waiting = DB::table('prc_process_work')->where('intent_id', $queued->id)->sole();

    // Устаревшая work active intent supersede-ится в той же tx, где появляется successor на текущем head.
    $lease = lifecycleClaimNextDetail() ?? throw new LogicException('Successor was not claimed.');
    $successor = DetailFixtures::input($lease->workId);
    /** @var DetailInput $successor */
    expect(DB::table('prc_process_work')->where('id', $stale->id)->value('state'))->toBe('superseded')
        ->and($successor->intentId->value)->toBe($first->id)
        ->and($successor->expectedHeadGeneration)->toBe(2)
        ->and(DB::table('prc_operation_intents')->where('id', $first->id)->value('expected_head_generation'))->toBe(2)
        ->and(DB::table('prc_process_run_work')->where('run_id', $runs['A']->value)->where('work_id', $stale->id)->value('accounting_role'))->toBe('observes')
        ->and(DB::table('prc_process_run_work')->where('run_id', $runs['A']->value)->where('work_id', $lease->workId->value->value)->value('accounting_role'))->toBe('required')
        // Pending intent не admitted и не supersede-ится, пока active intent не завершён.
        ->and(DB::table('prc_process_work')->where('id', $waiting->id)->value('state'))->toBe('ready')
        ->and(app(RunProjector::class)->summary($runs['B'])?->state)->toBe(ProcessRunState::Running);
    lifecycleFinishDetail($lease, 'A');

    // Завершение active intent активирует pending с пересчитанным expected head.
    $head = (int) DB::table('prc_procurements')->value('head_generation');
    expect(DB::table('prc_operation_intents')->where('id', $first->id)->value('state'))->toBe('terminal')
        ->and(DB::table('prc_operation_intents')->where('id', $queued->id)->value('state'))->toBe('active')
        ->and(DB::table('prc_operation_intents')->where('id', $queued->id)->value('expected_head_generation'))->toBe($head)
        ->and(app(RunProjector::class)->summary($runs['A'])?->state)->toBe(ProcessRunState::Completed);

    $lease = lifecycleClaimNextDetail() ?? throw new LogicException('Activated intent work was not claimed.');
    $second = DetailFixtures::input($lease->workId);
    /** @var DetailInput $second */
    expect($second->intentId->value)->toBe($queued->id)
        ->and($second->expectedHeadGeneration)->toBe($head)
        ->and(DB::table('prc_process_run_work')->where('run_id', $runs['B']->value)->where('work_id', $lease->workId->value->value)->value('accounting_role'))->toBe('required');
    lifecycleFinishDetail($lease, 'B');

    foreach ($runs as $run) {
        $summary = app(RunProjector::class)->summary($run);
        expect($summary?->state)->toBe(ProcessRunState::Completed)
            ->and($summary?->required->superseded + $summary?->required->failedItem + $summary?->required->cancelled)->toBe(0);
    }
    expect(DB::table('prc_process_work')->where('stage', 'detail')->whereIn('state', ['ready', 'admitted', 'running', 'deferred', 'blocked'])->count())->toBe(0)
        ->and(lifecycleDetailIntents($procurement, 'active'))->toBe([])
        ->and(lifecycleDetailIntents($procurement, 'pending'))->toBe([]);
});

test('failed_item и cancel освобождают detail intent: очередь subject продвигается, refresh не блокируется', function (): void {
    $profile = EisCrawlerFactory::new()->create();
    $scheduler = app(AcquisitionScheduler::class);
    $launch = lifecycleLaunch($profile);
    foreach (['A' => 'lifecycle-release-a', 'B' => 'lifecycle-release-b', 'C' => 'lifecycle-release-c'] as $content => $command) {
        lifecycleDrainListing($scheduler->manual($launch, ListingStoreRepository::id($command), null), [ListingFixtures::summary('lifecycle-release', $content)]);
    }
    $procurement = (int) DB::table('prc_procurements')->value('id');
    expect(lifecycleDetailIntents($procurement, 'pending'))->toHaveCount(2);
    [$first] = lifecycleDetailIntents($procurement, 'active');
    [$second, $third] = lifecycleDetailIntents($procurement, 'pending');

    $failure = new StageFailedItem(ItemFailureReason::ContractRejected, new ArtifactRef('fixture', 'reject', ContentHash::of('reject')));
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        if ($attempt > 1) {
            DB::update("UPDATE prc_process_work SET due_at = clock_timestamp() - interval '1 second' WHERE stage = 'detail' AND state = 'deferred'");
        }
        $lease = lifecycleClaimNextDetail() ?? throw new LogicException('Detail was not claimed.');
        expect(DetailFixtures::input($lease->workId))->toHaveProperty('intentId', new ProcessUuid($first->id));
        app(WorkFinalizer::class)->finish($lease, $failure);
    }
    expect(DB::table('prc_process_work')->where('intent_id', $first->id)->where('state', 'failed_item')->count())->toBe(1)
        ->and(DB::table('prc_operation_intents')->where('id', $first->id)->value('state'))->toBe('terminal')
        ->and(DB::table('prc_operation_intents')->where('id', $second->id)->value('state'))->toBe('active');

    $this->artisan('guard:sync-roles')->assertSuccessful();
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $work = DB::table('prc_process_work')->where('intent_id', $second->id)->sole();
    $cancel = app(ProcessOperatorCommands::class)->cancelWork($operator, new WorkId(new ProcessUuid($work->id)), $work->transition_seq, 'operator cancel');
    expect($cancel->status->value)->toBe('applied')
        ->and(DB::table('prc_operation_intents')->where('id', $second->id)->value('state'))->toBe('terminal')
        ->and(DB::table('prc_operation_intents')->where('id', $third->id)->value('state'))->toBe('active');

    $lease = lifecycleClaimNextDetail() ?? throw new LogicException('Third intent work was not claimed.');
    lifecycleFinishDetail($lease, 'C');
    expect(lifecycleDetailIntents($procurement, 'active'))->toBe([])
        ->and(lifecycleDetailIntents($procurement, 'pending'))->toBe([]);
    DB::update("UPDATE prc_operation_intents SET next_due_at = clock_timestamp() - interval '1 second' WHERE id = ?", [$third->id]);
    expect(app(RefreshRepository::class)->scheduleDetail($procurement))->not->toBeNull();
});

test('manual retry failed_item detail завершает active intent на неизменном head и после supersede re-pin', function (): void {
    $this->artisan('guard:sync-roles')->assertSuccessful();
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $commands = app(ProcessOperatorCommands::class);
    $profile = EisCrawlerFactory::new()->create();
    $scheduler = app(AcquisitionScheduler::class);
    $launch = lifecycleLaunch($profile);

    $runA = $scheduler->manual($launch, ListingStoreRepository::id('retry-lifecycle-a'), null);
    lifecycleDrainListing($runA, [ListingFixtures::summary('retry-lifecycle-item', 'v1')]);
    $procurement = (int) DB::table('prc_procurements')->value('id');
    $failure = new StageFailedItem(ItemFailureReason::ContractRejected, new ArtifactRef('fixture', 'reject', ContentHash::of('reject')));

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        if ($attempt > 1) {
            DB::update("UPDATE prc_process_work SET due_at = clock_timestamp() - interval '1 second' WHERE stage = 'detail' AND state = 'deferred'");
        }
        $lease = lifecycleClaimNextDetail() ?? throw new LogicException('Initial detail was not claimed.');
        app(WorkFinalizer::class)->finish($lease, $failure);
    }

    $failedRow = DB::table('prc_process_work')->where('stage', 'detail')->where('state', 'failed_item')->sole();
    $retry1 = $commands->retryFailedItem($operator, new WorkId(new ProcessUuid($failedRow->id)), $failedRow->transition_seq, ListingStoreRepository::id('retry-cmd-1'), 'retry after source fix');
    expect($retry1->status->value)->toBe('applied')->and($retry1->successor)->not->toBeNull();

    // На неизменном head manual retry сам доходит до failed_item, а повторный manual retry переживает advance head и supersede re-pin.
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        if ($attempt > 1) {
            DB::update("UPDATE prc_process_work SET due_at = clock_timestamp() - interval '1 second' WHERE stage = 'detail' AND state = 'deferred'");
        }
        $lease = lifecycleClaimNextDetail() ?? throw new LogicException('First retry detail was not claimed.');
        app(WorkFinalizer::class)->finish($lease, $failure);
    }

    $failedRetryRow = DB::table('prc_process_work')->where('id', $retry1->successor->value->value)->sole();
    $retry2 = $commands->retryFailedItem($operator, $retry1->successor, $failedRetryRow->transition_seq, ListingStoreRepository::id('retry-cmd-2'), 'second retry before head advance');
    expect($retry2->status->value)->toBe('applied')->and($retry2->successor)->not->toBeNull();

    // Второй run продвигает head закупки, пока manual retry ждёт claim: carrySupersededRequirement обязан перенести required link на re-pinned successor.
    $runB = $scheduler->manual($launch, ListingStoreRepository::id('retry-lifecycle-b'), null);
    lifecycleDrainListing($runB, [ListingFixtures::summary('retry-lifecycle-item', 'v2')]);
    expect(lifecycleDetailIntents($procurement, 'active'))->toHaveCount(1)
        ->and(lifecycleDetailIntents($procurement, 'pending'))->toHaveCount(1);

    $repinnedLease = lifecycleClaimNextDetail() ?? throw new LogicException('Re-pinned manual retry successor was not claimed.');
    expect(DB::table('prc_process_work')->where('id', $retry2->successor->value->value)->value('state'))->toBe('superseded')
        ->and(DB::table('prc_process_run_work')->where('run_id', $runA->value)->where('work_id', $repinnedLease->workId->value->value)->value('accounting_role'))->toBe('required');
    lifecycleFinishDetail($repinnedLease, 'v2-retry');
    expect(app(RunProjector::class)->summary($runA)?->state)->toBe(ProcessRunState::Completed);

    // Очередной pending intent второго run активируется, затем доводится до failed_item и успешно завершается через manual retry без смены head.
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        if ($attempt > 1) {
            DB::update("UPDATE prc_process_work SET due_at = clock_timestamp() - interval '1 second' WHERE stage = 'detail' AND state = 'deferred'");
        }
        $leaseB = lifecycleClaimNextDetail() ?? throw new LogicException('Run B detail was not claimed.');
        app(WorkFinalizer::class)->finish($leaseB, $failure);
    }
    $failedB = DB::table('prc_process_work')->where('stage', 'detail')->where('state', 'failed_item')->orderByDesc('created_at')->first()
        ?? throw new LogicException('Missing failed Run B detail.');
    $retryB = $commands->retryFailedItem($operator, new WorkId(new ProcessUuid($failedB->id)), $failedB->transition_seq, ListingStoreRepository::id('retry-cmd-b'), 'retry on unchanged head');
    expect($retryB->status->value)->toBe('applied')->and($retryB->successor)->not->toBeNull();

    $unchangedLease = lifecycleClaimNextDetail() ?? throw new LogicException('Unchanged-head manual retry was not claimed.');
    expect($unchangedLease->workId->value->value)->toBe($retryB->successor->value->value);
    lifecycleFinishDetail($unchangedLease, 'v2-final');

    expect(app(RunProjector::class)->summary($runB)?->state)->toBe(ProcessRunState::Completed)
        ->and(lifecycleDetailIntents($procurement, 'active'))->toBe([])
        ->and(lifecycleDetailIntents($procurement, 'pending'))->toBe([]);
});

test('actual producer: пауза площадки A не блокирует listing и закрытие discovery площадки B', function (): void {
    $eis = EisCrawlerFactory::new()->create();
    $other = Registry::factory()->create();
    $second = EisCrawlerFactory::new()->create();
    // Профиль второй площадки: тот же typed listing contract, другой platform/registry scope.
    DB::table('prc_crawlers')->where('id', $second->id)->update(['registry_id' => $other->id]);
    $second = $second->fresh() ?? throw new LogicException('Missing profile.');
    $scheduler = app(AcquisitionScheduler::class);
    $runA = $scheduler->manual(lifecycleLaunch($eis), ListingStoreRepository::id('t02-a'), null);
    $runB = $scheduler->manual(lifecycleLaunch($second), ListingStoreRepository::id('t02-b'), null);
    expect(DB::table('prc_process_work')->where('stage', 'listing')->pluck('cohort_key')->sort()->values()->all())
        ->toBe(collect(['platform:'.$eis->registry->platform_id, 'platform:'.$other->platform_id])->sort()->values()->all());

    $lane = app(ProcessPolicy::class)->parentLane(StageCode::Listing);
    app(WorkFeeder::class)->tick($lane);
    $paused = ExecutionFixtures::setMode('acquisition:platform:'.$eis->registry->platform_id, ControlMode::Paused);
    expect($paused->status->value)->toBe('applied');
    $rootA = ListingFixtures::next(lifecycleCrawl($runA)) ?? throw new LogicException('Missing A root.');
    expect(fn () => FinalizeFixtures::running($rootA['work']))->toThrow(LogicException::class, 'paused');

    lifecycleDrainListing($runB, []);
    expect(DB::table('prc_process_runs')->where('id', $runB->value)->value('discovery_outcome'))->toBe('complete')
        ->and(app(RunProjector::class)->summary($runB)?->state)->toBe(ProcessRunState::Completed)
        ->and(DB::table('prc_process_runs')->where('id', $runA->value)->value('discovery_outcome'))->toBe('collecting')
        ->and(DB::table('prc_process_work')->where('id', $rootA['work']->value->value)->value('state'))->not->toBe('running');

    ExecutionFixtures::setMode('acquisition:platform:'.$eis->registry->platform_id, ControlMode::Enabled);
    lifecycleDrainListing($runA, []);
    expect(DB::table('prc_process_runs')->where('id', $runA->value)->value('discovery_outcome'))->toBe('complete');
});

test('final sidebar preserves a real acquisition run without legacy circuits or incidents', function (): void {
    $this->artisan('guard:sync-roles')->assertSuccessful();
    $profile = EisCrawlerFactory::new()->create();
    $run = app(AcquisitionScheduler::class)->manual(lifecycleLaunch($profile), ListingStoreRepository::id('exr-01'), null);
    $admin = User::factory()->create();
    $admin->assignRole(SuperAdminRole::class);

    $html = actingAs($admin)->get(ProcessRunResource::getUrl())->assertOk()->getContent();

    expect(Schema::hasTable('prc_source_circuits'))->toBeFalse()
        ->and(collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn (string $name): bool => str_contains($name, 'source-circuits'))->all())->toBe([])
        ->and($html)->not->toContain('source-incidents')
        ->and($html)->toContain('Процессы: запуски')
        ->and($html)->toContain('Процессы: управление')
        ->and($html)->not->toContain('source-circuits')
        ->and(DB::table('prc_process_runs')->where('id', $run->value)->exists())->toBeTrue();
});
