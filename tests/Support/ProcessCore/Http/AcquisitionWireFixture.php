<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore\Http;

use App\Dto\Platforms\Eis\EisCardRoutes;
use App\Dto\Platforms\Eis\EisListingFilter;
use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Procurement\Acquisition\Detail\DetailRequest;
use App\Dto\Procurement\Acquisition\Listing\ListingFetched;
use App\Dto\Procurement\Acquisition\Listing\ListingRequest;
use App\Dto\Procurement\Acquisition\Listing\ListingResult;
use App\Dto\Procurement\Platform\Definition\PlatformDefinition;
use App\Dto\Procurement\Platform\Diagnostics\DiagnosticResponse;
use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Outcomes\StageDeferred;
use App\Dto\Procurement\Process\Outcomes\StageOutcome;
use App\Dto\Procurement\Process\Outcomes\StageSucceeded;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\Duration;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\RetryAt;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Outcomes\DeferralReason;
use App\Models\Procurement\Platform\Registry;
use App\Repositories\Procurement\Crawler\ListingStoreRepository;
use App\Repositories\Procurement\Process\Work\WorkStoreRepository;
use App\Services\Platforms\Eis\EisListingPartitioner;
use App\Services\Platforms\Eis\EisListingSource;
use App\Services\Procurement\Acquisition\Diagnostics\AcquisitionDiagnostic;
use App\Services\Procurement\Acquisition\Diagnostics\AcquisitionValidation;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Platform\FetchOutcomeBridge;
use App\Services\Procurement\Platform\PlatformRegistry;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Services\Procurement\Proxyma\Catalog\ProfileResolver;
use Database\Factories\Platforms\Eis\EisCrawlerFactory;
use Illuminate\Support\Facades\DB;
use Tests\Support\Platforms\Contract\RecordingOutbound;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\HistoricalNetworkFixtures;
use Tests\Support\ProcessCore\ListingFixtures;
use Tests\Support\ProcessCore\ProxymaCallerFixtures;

/** Real typed adapter, diagnostic owner, PostgreSQL permits and local HTTP/TLS. */
final readonly class AcquisitionWireFixture
{
    public PlatformDefinition $definition;

    public Registry $registry;

    public ProcessUuid $routeProfile;

    public function __construct(public WireFixture $wire, string $listingPath = '/csv')
    {
        $network = new PermitFixtures;
        config([
            'platforms.eis.endpoints.listing.url' => $wire->url($listingPath)->reveal(),
            'platforms.eis.endpoints.card.url' => $wire->url('/card', true)->reveal(),
            'outbound.http.tls_bundles' => ['fixture.test' => $wire->root.'/cert.pem'],
        ]);
        $platforms = new PlatformRegistry(config());
        app()->instance(PlatformRegistry::class, $platforms);
        $this->definition = $platforms->get(new PlatformCode('eis'));
        $this->registry = EisCrawlerFactory::new()->make()->registry
            ?? throw new \LogicException('Missing fixture registry.');
        app(SourceCatalogSync::class)->sync($this->definition->sourceTargets);
        HistoricalNetworkFixtures::synchronize();
        $key = app(OutboundPolicy::class)->defaultRouteProfile;
        HistoricalNetworkFixtures::profile($key, [$network->route->routeId->value]);
        $this->routeProfile = new ProcessUuid(DB::table('prc_network_route_profiles')->where('profile_key', $key)->sole()->id);
        $routes = [];
        foreach (['common-info', 'documents', 'event-journal', 'supplier-results'] as $tab) {
            $routes[$tab] = $wire->url('/'.$tab.'?regNumber={regNumber}', true)->reveal();
        }
        app()->instance(EisCardRoutes::class, new EisCardRoutes(['ea20' => $routes]));
        app()->instance(AcquisitionDiagnostic::class, app()->makeWith(AcquisitionDiagnostic::class, ['urls' => $wire->policy()]));
        CallerFixtures::healthReady();
        ProxymaCallerFixtures::install($wire);
    }

    public function listingRequest(string $day = '2026-10-31'): ListingRequest
    {
        $filter = new EisListingFilter(['8873921']);
        $plan = (new EisListingPartitioner)->plan($filter, $day, $day);

        return new ListingRequest('fz44', $filter, $plan->windows[0]->cursor);
    }

    public function fetch(ListingRequest|DetailRequest $request): DiagnosticResponse
    {
        return app(AcquisitionDiagnostic::class)->fetch($this->definition, $this->registry->platform_id,
            $request, app(ProfileResolver::class)->profile($this->routeProfile), ContentHash::of('fixture-input'), $this->wire->policy());
    }

    /** Ordinary Process owner, so physical retry checks do not rely on a one-send canary. */
    public function fetchWork(ListingRequest $request, ?\Closure $before = null): StageOutcome
    {
        ExecutionFixtures::authority([StageCode::Listing]);
        ListingFixtures::bindLocal();
        DB::table('prc_adapter_health')->update(['state' => 'healthy']);
        $run = ListingFixtures::run(registry: $this->registry);
        DB::table('prc_process_work')->where('id', $run['work']->value->value)->update(['platform_id' => $this->registry->platform_id]);
        $lease = FinalizeFixtures::running($run['work']);
        $context = new ExecutionContext($lease,
            app(WorkStoreRepository::class),
            app(ProcessClock::class),
            Duration::seconds(240), Duration::seconds(360),
            Duration::seconds(15), Duration::seconds(15),
            Duration::seconds(30));
        $before?->__invoke($context);
        $endpoint = $this->definition->endpoints->get('listing');
        $target = $this->definition->sourceTargets->targets[$endpoint->targetKey];
        $targetId = new ProcessUuid(DB::table('prc_source_targets')->where('platform_id', $this->registry->platform_id)
            ->where('target_key', $target->key)->sole()->id);
        $source = new EisListingSource(new EisPolicy(config()),
            new RecordingOutbound, $targetId);

        return app(AcquisitionValidation::class)->run($target->adapterContract,
            $this->registry->platform_id, app(ProfileResolver::class)->profile($this->routeProfile), $source->plan($request),
            $this->wire->policy(), $context, function ($outbound) use ($targetId, $request, $context, $run) {
                $source = new EisListingSource(new EisPolicy(config()), $outbound, $targetId);
                $result = $source->fetch($request, $context);
                if (! $result instanceof ListingFetched) {
                    return app(FetchOutcomeBridge::class)->failure($result);
                }
                if (! $result->page->assessment->usable()) {
                    return new StageDeferred(DeferralReason::ItemRetry,
                        new RetryAt(new \DateTimeImmutable('+1 minute')));
                }

                return new StageSucceeded(new ListingResult(
                    app(ListingStoreRepository::class)->task($run['input']), $result->page));
            });
    }
}
