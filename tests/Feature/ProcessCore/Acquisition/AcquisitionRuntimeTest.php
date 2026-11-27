<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisListingFilter;
use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Procurement\Acquisition\Control\AcquisitionLaunch;
use App\Dto\Procurement\Acquisition\Fetch\ValidatedAcquisitionResult;
use App\Dto\Procurement\Acquisition\Listing\ListingRequest;
use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Outcomes\StageSucceeded;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\Duration;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\VersionedPayload;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Process\Core\AcquisitionKind;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Repositories\Procurement\Process\Work\WorkStoreRepository;
use App\Services\Platforms\Eis\EisListingPartitioner;
use App\Services\Platforms\Eis\EisListingSource;
use App\Services\Procurement\Acquisition\Orchestration\AcquisitionHandler;
use App\Services\Procurement\Acquisition\Orchestration\AcquisitionRuntime;
use App\Services\Procurement\Acquisition\Orchestration\AcquisitionScheduler;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Platform\Contracts\PlatformOutbound;
use App\Services\Procurement\Platform\PlatformRegistry;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Process\Execution\ProcessAuthoritySync;
use App\Services\Procurement\Process\Execution\StageRegistry;
use App\Services\Procurement\Process\Execution\WorkFinalizer;
use App\Services\Procurement\Process\Infrastructure\ProcessPayloadCodec;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use Database\Factories\Platforms\Eis\EisCrawlerFactory;
use Illuminate\Support\Facades\DB;
use Tests\Support\Platforms\Contract\RecordingOutbound;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\HistoricalNetworkFixtures;
use Tests\Support\ProcessCore\HistoricalRouteCatalog as RouteRepository;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\PackageNetworkFacts;
use Tests\Support\ProcessCore\ProxymaCallerFixtures;
use Tests\Support\ProcessCore\RawEvidenceFixtures;

beforeEach(function (): void {
    F::clean();
    $this->rawEvidence = new RawEvidenceFixtures;
});
afterEach(function (): void {
    F::clean();
    $this->rawEvidence->clean();
});

test('real EIS producer work runs through registry composition local HTTP canary and fenced publication', function (): void {
    $wire = new WireFixture;
    try {
        $f = new F;
        config(['platforms.eis.endpoints.listing.url' => $wire->url('/listing')->reveal()]);
        $platforms = new PlatformRegistry(config());
        app()->instance(PlatformRegistry::class, $platforms);
        $definition = $platforms->get(new PlatformCode('eis'));
        $profile = EisCrawlerFactory::new()->create();
        app(SourceCatalogSync::class)->sync($definition->sourceTargets);
        HistoricalNetworkFixtures::synchronize();
        $routeKey = app(OutboundPolicy::class)->defaultRouteProfile;
        app(ProcessTransaction::class)->run(fn () => app(RouteRepository::class)->ensureProfile($routeKey, $f->route->routeId->value));
        $route = DB::table('prc_network_route_profiles')->where('profile_key', $routeKey)->sole();
        ProxymaCallerFixtures::install($wire);
        app()->forgetInstance(StageRegistry::class);
        $stages = app(StageRegistry::class);
        expect($stages->handlerVersion(StageCode::Listing))->toBe(AcquisitionRuntime::VERSION);
        app(ProcessAuthoritySync::class)->ensureBaseline();
        foreach ([StageCode::Listing, StageCode::Detail, StageCode::StatusRefresh] as $stage) {
            app(ProcessAuthoritySync::class)->registerHandler($stage, AcquisitionRuntime::VERSION);
        }
        $partitioner = new EisListingPartitioner;
        $filter = new EisListingFilter(['123']);
        $settings = new VersionedPayload(json_encode(['profile_version' => 1, 'route_profile_id' => $route->id,
            'route_policy_version' => $route->policy_version, 'kind' => 'archive',
            'initial_from' => '2026-01-01T00:00:00+00:00', 'through' => '2026-01-01T23:59:59+00:00'], JSON_THROW_ON_ERROR), 1);
        $launch = new AcquisitionLaunch($profile->id, 1, $profile->registry->platform_id, $profile->registry_id, 'fz44',
            AcquisitionKind::Archive, '1', $partitioner->snapshot($filter), ContentHash::of('access'), ContentHash::of('stream'),
            $partitioner->plan($filter, '2026-01-01', '2026-01-01'), $settings);
        $run = app(AcquisitionScheduler::class)->manual($launch, LedgerFixtures::uuid(), null);
        $row = DB::table('prc_process_work')->where('origin_run_id', $run->value)->sole();
        $work = new WorkId(new ProcessUuid($row->id));
        $input = (new ProcessPayloadCodec)->decodeInput(new VersionedPayload($row->input_payload, $row->input_schema_version));
        $task = app(ListingStoreRepository::class)->task($input);
        $target = DB::table('prc_source_targets')->where('platform_id', $profile->registry->platform_id)->where('target_key', $definition->endpoints->get('listing')->targetKey)->sole();
        $source = new EisListingSource(new EisPolicy(config()), new RecordingOutbound, new ProcessUuid($target->id));
        $plan = $source->plan(new ListingRequest('fz44', $filter, $task->cursor, [], AcquisitionKind::Archive));
        $url = $plan->url->reveal();
        $wire->respond(parse_url($url, PHP_URL_PATH),
            file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Csv/neutral-empty.csv')), headers: ['Content-Type' => 'text/csv; charset=UTF-8']);
        $lease = FinalizeFixtures::running($work);
        $context = new ExecutionContext($lease, app(WorkStoreRepository::class), app(ProcessClock::class),
            Duration::seconds(240), Duration::seconds(360), Duration::seconds(15), Duration::seconds(15), Duration::seconds(30));
        $handler = app()->makeWith(AcquisitionHandler::class, ['stage' => StageCode::Listing, 'urls' => $wire->policy()]);
        $outcome = $handler->execute($input, $context);
        expect($outcome)->toBeInstanceOf(StageSucceeded::class)->and($outcome->result)->toBeInstanceOf(ValidatedAcquisitionResult::class);
        app(WorkFinalizer::class)->finish($context->lease(), $outcome);
        expect(DB::table('prc_process_work')->where('id', $row->id)->sole()->state)->toBe('succeeded')
            ->and(PackageNetworkFacts::attempts()->count())->toBe(1)
            ->and(DB::table('prc_outbound_operations')->where('kind', 'legacy_bridge')->count())->toBe(0)
            ->and(DB::table('prc_outbound_operations')->sole()->parent_attempt_id)->toBe($lease->attemptId->value)
            ->and($wire->receipts())->toHaveCount(1)
            ->and(app()->bound(PlatformOutbound::class))->toBeFalse();
    } finally {
        $wire->stop();
    }
})->group('acquisition-operations', 'run-state-reduction', 'provider-send-fencing');
