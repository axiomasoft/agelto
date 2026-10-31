<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisListingFilter;
use App\Dto\Procurement\Acquisition\ListingFetched;
use App\Dto\Procurement\Acquisition\ListingFetchResult;
use App\Dto\Procurement\Acquisition\ListingRequest;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Platform\PlatformCode;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Health\PlatformCheckVerdict;
use App\Enums\Procurement\Network\AdapterVerdict;
use App\Enums\Procurement\Process\ControlMode;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Repositories\Procurement\Network\RouteRepository;
use App\Repositories\Procurement\Process\ControlRepository;
use App\Services\Platforms\Eis\Diagnostics\ListingFilterCheck;
use App\Services\Platforms\Eis\EisListingPartitioner;
use App\Services\Platforms\Eis\EisListingSource;
use App\Services\Procurement\Acquisition\AcquisitionDiagnostic;
use App\Services\Procurement\Acquisition\DiagnosticRunner;
use App\Services\Procurement\Health\HealthBaselineReader;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Platform\Contracts\ListingSource;
use App\Services\Procurement\Platform\PlatformRegistry;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Proxy\ProxyResolver;
use Database\Factories\Platforms\Eis\EisCrawlerFactory;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\RawEvidenceFixtures;

beforeEach(function (): void {
    F::clean();
    $this->rawEvidence = new RawEvidenceFixtures;
});
afterEach(function (): void {
    F::clean();
    $this->rawEvidence->clean();
});

test('T17 standalone adapter canary validates through the new owner without domain publication', function (): void {
    $wire = new WireFixture;
    try {
        $fixture = new F;
        config(['platforms.eis.endpoints.listing.url' => $wire->url('/listing')->reveal()]);
        $definition = (new PlatformRegistry(config()))->get(new PlatformCode('eis'));
        $profile = EisCrawlerFactory::new()->create();
        app(SourceCatalogSync::class)->sync($definition->sourceTargets);
        $control = DB::table('prc_control_scopes')->where('scope_key', 'acquisition')->sole();
        app(ProcessTransaction::class)->run(fn () => app(ControlRepository::class)
            ->setMode('acquisition', ControlMode::Paused, null, 'Diagnostic while paused',
                $control->control_version, LedgerFixtures::uuid(), LedgerFixtures::uuid()));
        $routeKey = app(OutboundPolicy::class)->defaultRouteProfile;
        $route = app(ProcessTransaction::class)->run(fn () => app(RouteRepository::class)->ensureProfile($routeKey, $fixture->route->routeId->value));
        $routeId = DB::table('prc_network_route_profiles')->where('profile_key', $routeKey)->value('id');
        $filter = new EisListingFilter(['123']);
        $plan = (new EisListingPartitioner)->plan($filter, '2026-01-01', '2026-01-01');
        $wire->respond('/listing', file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Csv/neutral-empty.csv')),
            headers: ['Content-Type' => 'text/csv; charset=UTF-8']);
        $result = app(AcquisitionDiagnostic::class)->fetch($definition, $profile->registry->platform_id,
            new ListingRequest('fz44', $filter, $plan->windows[0]->cursor),
            app(ProxyResolver::class)->profile(new ProcessUuid($routeId)), ContentHash::of('diagnostic-input'), $wire->policy());
        expect($result->value)->toBeInstanceOf(ListingFetched::class)
            ->and($result->receipt?->verdict)->toBe(AdapterVerdict::Accepted)
            ->and(DB::table('prc_process_work')->count())->toBe(0)
            ->and(DB::table('prc_crawl_blocks')->count())->toBe(0)
            ->and(DB::table('prc_procurements')->count())->toBe(0)
            ->and(DB::table('prc_request_attempts')->count())->toBe(1)
            ->and(DB::table('prc_network_demands')->distinct()->pluck('traffic_class')->all())->toBe(['diagnostic'])
            ->and(DB::table('prc_control_scopes')->where('scope_key', 'acquisition')->value('desired_mode'))->toBe('paused')
            ->and(DB::table('prc_outbound_operations')->sole()->kind)->toBe('probe')
            ->and(DB::table('prc_outbound_operations')->sole()->state)->toBe('closed')
            ->and($wire->receipts())->toHaveCount(1);
    } finally {
        $wire->stop();
    }
})->group('t17', 't35', 'p23');

test('T17 registered diagnostic runner prepares two bounded reads and keeps canonical state untouched', function (): void {
    $wire = new WireFixture;
    try {
        $fixture = new F;
        config(['platforms.eis.endpoints.listing.url' => $wire->url('/listing')->reveal()]);
        $definition = (new PlatformRegistry(config()))->get(new PlatformCode('eis'));
        $profile = EisCrawlerFactory::new()->create();
        Okpd2Node::factory()->create([
            'platform_id' => $profile->registry->platform_id, 'code' => '43', 'external_id' => '123',
        ]);
        app(SourceCatalogSync::class)->sync($definition->sourceTargets);
        $key = app(OutboundPolicy::class)->defaultRouteProfile;
        app(ProcessTransaction::class)->run(fn () => app(RouteRepository::class)->ensureProfile($key, $fixture->route->routeId->value));
        app()->instance(AcquisitionDiagnostic::class, app()->makeWith(AcquisitionDiagnostic::class, ['urls' => $wire->policy()]));
        $body = file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Csv/neutral-valid.csv'));
        $wire->respond('/listing', $body, headers: ['Content-Type' => 'text/csv; charset=UTF-8']);
        $wire->respond('/listing', str_replace('00042', '00043', $body), headers: ['Content-Type' => 'text/csv; charset=UTF-8']);
        $result = app(DiagnosticRunner::class)->run(
            new ListingFilterCheck, $definition, $profile->registry->platform_id,
            app(HealthBaselineReader::class)->read(), new DateTimeImmutable('2026-11-01T12:00:00Z'));
        expect($result->verdict)->toBe(PlatformCheckVerdict::Ok)
            ->and($result->requestsUsed)->toBe(2)->and($result->measured['overlap'])->toBe(0)
            ->and($wire->receipts())->toHaveCount(2)->and(DB::table('prc_request_attempts')->count())->toBe(2)
            ->and(DB::table('prc_process_work')->count())->toBe(0)->and(DB::table('prc_procurements')->count())->toBe(0);
        foreach ($wire->receipts() as $index => $receipt) {
            parse_str(parse_url($receipt['path'], PHP_URL_QUERY) ?? '', $query);
            expect($query['from'])->toBe('1')->and($query['to'])->toBe('500')
                ->and($query['publishDateFrom'])->toBe('31.10.2026')->and($query['publishDateTo'])->toBe('31.10.2026');
            if ($index === 0) {
                expect($query)->not->toHaveKey('okpd2Ids');
            } else {
                expect($query['okpd2Ids'])->toBe('123');
            }
        }
    } finally {
        $wire->stop();
    }
})->group('t17', 'p23');

test('T17 diagnostic failures retain physical evidence without publishing or blindly retrying parsing', function (string $failure): void {
    $wire = new WireFixture;
    try {
        $fixture = new F;
        config(['platforms.eis.endpoints.listing.url' => $wire->url('/listing')->reveal()]);
        $definition = (new PlatformRegistry(config()))->get(new PlatformCode('eis'));
        $profile = EisCrawlerFactory::new()->create();
        Okpd2Node::factory()->create(['platform_id' => $profile->registry->platform_id, 'code' => '43', 'external_id' => '123']);
        app(SourceCatalogSync::class)->sync($definition->sourceTargets);
        $key = app(OutboundPolicy::class)->defaultRouteProfile;
        app(ProcessTransaction::class)->run(fn () => app(RouteRepository::class)->ensureProfile($key, $fixture->route->routeId->value));
        app()->instance(AcquisitionDiagnostic::class, app()->makeWith(AcquisitionDiagnostic::class, ['urls' => $wire->policy()]));
        $body = match ($failure) {
            'empty_response' => '',
            'invalid_csv' => 'wrong,header',
            default => file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Csv/neutral-valid.csv')),
        };
        for ($i = 0; $i < ($failure === 'dependency' ? 3 : 1); $i++) {
            $wire->respond('/listing', $body, status: $failure === 'dependency' ? 503 : 200,
                headers: ['Content-Type' => 'text/csv; charset=UTF-8']);
        }
        if ($failure === 'no_route') {
            DB::table('prc_network_routes')->update(['enabled' => false]);
        } elseif ($failure === 'rate_limit') {
            DB::table('prc_network_budgets')->update(['not_before' => DB::raw("clock_timestamp() + interval '1 hour'")]);
        } elseif ($failure === 'parser_fault') {
            // The physical read is real; only the post-response parser fault is injected.
            app()->bind(EisListingSource::class, function ($container) {
                return new class($container->build(EisListingSource::class)) implements ListingSource
                {
                    public function __construct(private ListingSource $source) {}

                    public function fetch(ListingRequest $request, ExecutionContext|OutboundExecutionContext $context): ListingFetchResult
                    {
                        $this->source->fetch($request, $context);
                        throw new LogicException('injected diagnostic parser fault');
                    }
                };
            });
        }
        $run = fn () => app(DiagnosticRunner::class)->run(new ListingFilterCheck, $definition, $profile->registry->platform_id,
            app(HealthBaselineReader::class)->read(), new DateTimeImmutable('2026-11-01T12:00:00Z'));
        $physical = match ($failure) {
            'no_route', 'rate_limit' => 0,
            // An initial unknown canary authorizes only one physical attempt.
            'dependency' => 1,
            default => 1,
        };
        if ($failure === 'parser_fault') {
            expect($run)->toThrow(LogicException::class, 'injected diagnostic parser fault');
            expect(DB::table('prc_observations')->where('kind', 'adapter_validation')->count())->toBe(1);
            $evidence = json_decode(DB::table('prc_observations')->where('kind', 'adapter_validation')->sole()->details, true);
            expect($evidence['verdict'])->toBe('handler_fault');
        } else {
            $result = $run();
            expect($result->verdict)->toBe(PlatformCheckVerdict::NotEnoughData)
                ->and($result->requestsUsed)->toBe($physical);
        }
        expect($wire->receipts())->toHaveCount($physical)
            ->and(DB::table('prc_request_attempts')->count())->toBe($physical)
            ->and(DB::table('prc_procurements')->count())->toBe(0)
            ->and(DB::table('prc_process_work')->count())->toBe(0)
            ->and(DB::table('prc_outbound_operations')->where('state', '!=', 'closed')->count())->toBe(0);
    } finally {
        $wire->stop();
    }
})->with(['no_route', 'rate_limit', 'parser_fault', 'dependency', 'empty_response', 'invalid_csv'])->group('t17', 't35', 'p23');
