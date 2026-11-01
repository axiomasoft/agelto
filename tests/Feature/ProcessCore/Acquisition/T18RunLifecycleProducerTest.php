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
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\DetailInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Platform\SnapshotConsistency;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\DiscoveryOutcome;
use App\Enums\Procurement\Process\ProcessRunState;
use App\Enums\Procurement\Process\StageCode;
use App\Filament\Resources\Procurement\Crawler\SourceIncidents\SourceIncidentResource;
use App\Filament\Resources\Procurement\Process\Runs\ProcessRunResource;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\SourceIncident;
use App\Models\Procurement\Platform\Registry;
use App\Models\User;
use App\Repositories\Procurement\Crawler\DetailSnapshotRepository;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Platforms\Eis\EisListingPartitioner;
use App\Services\Procurement\Acquisition\AcquisitionScheduler;
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

pest()->group('t17', 't18', 't02', 'p23');

// P2.19 acceptance: run lifecycle и независимость площадок через настоящий AcquisitionScheduler/ListingFinalizer/DetailFinalizer.

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

/** Feeder admission detail lane и claim каждой admitted detail work, как это делает worker delivery. */
function lifecycleDeliverDetails(): void
{
    for ($round = 0; $round < 3; $round++) {
        app(WorkFeeder::class)->tick(app(ProcessPolicy::class)->parentLane(StageCode::Detail));
        foreach (DB::table('prc_process_work')->where('stage', 'detail')->where('state', 'admitted')->pluck('id') as $id) {
            $work = new WorkId(new ProcessUuid($id));
            app(WorkStoreRepository::class)->claim($work, ExecutionFixtures::delivery($work));
        }
    }
}

test('T18 actual producer: две стабильные passes закрывают discovery, detail публикует document handoff, pending document intent не удерживает run', function (): void {
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

// Acceptance-спецификация observability#s04 и execution#s10. На кандидате P2.18 падает: обе detail works
// superseded без successor, intents остаются active/pending навсегда (дефект P2.15/P2.16, эскалация P2.19).
test('T18 actual producer: superseded required detail заменяется successor для всех нуждающихся runs, intent не остаётся зависшим', function (): void {
    $profile = EisCrawlerFactory::new()->create();
    $scheduler = app(AcquisitionScheduler::class);
    $launch = lifecycleLaunch($profile);
    $runs = [];
    // Второй run видит изменённое содержимое той же закупки: head растёт, прежняя detail input устаревает.
    foreach (['A' => 'lifecycle-supersede-a', 'B' => 'lifecycle-supersede-b'] as $content => $command) {
        $run = $scheduler->manual($launch, ListingStoreRepository::id($command), null);
        $runs[] = $run;
        lifecycleDrainListing($run, [ListingFixtures::summary('lifecycle-shared', $content)]);
    }
    $procurement = DB::table('prc_procurements')->sole();
    expect($procurement->head_generation)->toBe(2);

    lifecycleDeliverDetails();

    $live = DB::table('prc_process_work')->where('stage', 'detail')
        ->whereIn('state', ['ready', 'admitted', 'running', 'deferred'])->get();
    expect($live)->toHaveCount(1);
    $successor = $live->sole();
    $successorInput = DetailFixtures::input(new WorkId(new ProcessUuid($successor->id)));
    /** @var DetailInput $successorInput */
    expect($successorInput->expectedHeadGeneration)->toBe(2);
    foreach ($runs as $run) {
        expect(DB::table('prc_process_run_work')->where('run_id', $run->value)->where('work_id', $successor->id)->value('accounting_role'))
            ->toBe('required');
        $superseded = DB::table('prc_process_run_work as rw')->join('prc_process_work as w', 'w.id', '=', 'rw.work_id')
            ->where('rw.run_id', $run->value)->where('w.stage', 'detail')->where('w.state', 'superseded')->pluck('rw.accounting_role')->all();
        expect(array_unique($superseded))->toBe($superseded === [] ? [] : ['observes']);
    }
    $active = DB::table('prc_operation_intents')->where('stage', 'detail')->where('subject_id', (string) $procurement->id)->where('state', 'active')->get();
    expect($active)->toHaveCount(1)->and($active->sole()->id)->toBe($successorInput->intentId->value)
        ->and($active->sole()->expected_head_generation)->toBe(2);
});

test('T02 actual producer: пауза площадки A не блокирует listing и закрытие discovery площадки B', function (): void {
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

test('T17 EXR-01 после circuits UI/schema cutover: sidebar с реальным acquisition run строится без legacy circuits', function (): void {
    $this->artisan('guard:sync-roles')->assertSuccessful();
    $profile = EisCrawlerFactory::new()->create();
    $run = app(AcquisitionScheduler::class)->manual(lifecycleLaunch($profile), ListingStoreRepository::id('exr-01'), null);
    SourceIncident::factory()->create();
    $admin = User::factory()->create();
    $admin->assignRole(SuperAdminRole::class);

    $html = actingAs($admin)->get(ProcessRunResource::getUrl())->assertOk()->getContent();

    expect(Schema::hasTable('prc_source_circuits'))->toBeFalse()
        ->and(collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn (string $name): bool => str_contains($name, 'source-circuits'))->all())->toBe([])
        ->and($html)->toContain(SourceIncidentResource::getNavigationLabel())
        ->and($html)->toContain('Процессы: запуски')
        ->and($html)->toContain('Процессы: управление')
        ->and($html)->not->toContain('source-circuits')
        ->and(DB::table('prc_process_runs')->where('id', $run->value)->exists())->toBeTrue();
});
