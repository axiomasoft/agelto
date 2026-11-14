<?php

declare(strict_types=1);

use App\Dto\Procurement\Health\CheckResult;
use App\Dto\Procurement\Platform\PlatformCode;
use App\Enums\Procurement\Health\PlatformCheckVerdict;
use App\Models\Procurement\Platform\Okpd2Node;
use App\Services\Platforms\Eis\Diagnostics\ListingFilterCheck;
use App\Services\Procurement\Acquisition\AcquisitionDiagnostic;
use App\Services\Procurement\Acquisition\DiagnosticRunner;
use App\Services\Procurement\Health\HealthBaselineReader;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Platform\PlatformRegistry;
use App\Services\Procurement\Process\ProcessTransaction;
use Database\Factories\Platforms\Eis\EisCrawlerFactory;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\HistoricalNetworkFixtures;
use Tests\Support\ProcessCore\HistoricalRouteCatalog as RouteRepository;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\RawEvidenceFixtures;

beforeEach(function (): void {
    F::clean();
    $this->rawEvidence = new RawEvidenceFixtures;
});
afterEach(function (): void {
    F::clean();
    $this->rawEvidence->clean();
});

function runBoundaryDiagnostic(WireFixture $wire): CheckResult
{
    $fixture = new F;
    config(['platforms.eis.endpoints.listing.url' => $wire->url('/listing')->reveal()]);
    $definition = (new PlatformRegistry(config()))->get(new PlatformCode('eis'));
    $profile = EisCrawlerFactory::new()->create();
    Okpd2Node::factory()->create(['platform_id' => $profile->registry->platform_id, 'code' => '43', 'external_id' => '123']);
    app(SourceCatalogSync::class)->sync($definition->sourceTargets);
    HistoricalNetworkFixtures::synchronize();
    app(ProcessTransaction::class)->run(fn () => app(RouteRepository::class)->ensureProfile(
        app(OutboundPolicy::class)->defaultRouteProfile, $fixture->route->routeId->value));
    app()->instance(AcquisitionDiagnostic::class, app()->makeWith(AcquisitionDiagnostic::class, ['urls' => $wire->policy()]));

    return app(DiagnosticRunner::class)->run(new ListingFilterCheck, $definition, $profile->registry->platform_id,
        app(HealthBaselineReader::class)->read(), new DateTimeImmutable('2026-11-01T12:00:00Z'));
}

test('Process boundary diagnostic uses two bounded reads and stores sanitized evidence only', function (): void {
    $wire = new WireFixture;
    try {
        $body = file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Csv/neutral-valid.csv'));
        $wire->respond('/listing', $body, headers: ['Content-Type' => 'text/csv; charset=UTF-8']);
        $wire->respond('/listing', str_replace('00042', '00043', $body), headers: ['Content-Type' => 'text/csv; charset=UTF-8']);
        $result = runBoundaryDiagnostic($wire);
        expect($result->verdict)->toBe(PlatformCheckVerdict::Ok)->and($result->requestsUsed)->toBe(2)
            ->and($wire->receipts())->toHaveCount(2)->and(DB::table('prc_request_attempts')->count())->toBe(2)
            ->and(DB::table('prc_procurements')->count())->toBe(0)->and(DB::table('prc_process_work')->count())->toBe(0);
        foreach ($wire->receipts() as $receipt) {
            parse_str(parse_url($receipt['path'], PHP_URL_QUERY) ?? '', $query);
            expect($query['from'])->toBe('1')->and($query['to'])->toBe('500')
                ->and($query['publishDateFrom'])->toBe('31.10.2026')->and($query['publishDateTo'])->toBe('31.10.2026');
        }
        $facts = DB::table('prc_observations')->where('kind', 'adapter_validation')->pluck('details')->all();
        expect($facts)->toHaveCount(2)->and(json_encode($facts, JSON_THROW_ON_ERROR))->not->toContain($body);
    } finally {
        $wire->stop();
    }
});

test('Process boundary diagnostic stops on invalid CSV without false empty pages or blind parser retries', function (): void {
    $wire = new WireFixture;
    try {
        $wire->respond('/listing', '<html>Access denied</html>', headers: ['Content-Type' => 'text/csv; charset=UTF-8']);
        $result = runBoundaryDiagnostic($wire);
        expect($result->verdict)->toBe(PlatformCheckVerdict::NotEnoughData)->and($result->requestsUsed)->toBe(1)
            ->and($wire->receipts())->toHaveCount(1)->and(DB::table('prc_request_attempts')->count())->toBe(1)
            ->and(DB::table('prc_process_work')->count())->toBe(0)->and(DB::table('prc_procurements')->count())->toBe(0);
        $facts = DB::table('prc_observations')->where('kind', 'adapter_validation')->pluck('details')->all();
        expect($facts)->toHaveCount(1)->and(json_decode($facts[0], true)['verdict'])->toBe('contract_failure')
            ->and(json_encode([$result->toArray(), $facts], JSON_THROW_ON_ERROR))->not->toContain('Access denied');
    } finally {
        $wire->stop();
    }
});
