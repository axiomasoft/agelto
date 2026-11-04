<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\NetworkDemandTicket;
use App\Dto\Procurement\Network\NetworkRouteSelection;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Network\ScheduledNetworkPermit;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Platform\CanonicalOrigin;
use App\Dto\Procurement\Platform\OriginBudgetPolicy;
use App\Dto\Procurement\Platform\PlatformCode;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\NetworkChangeStatus;
use App\Enums\Procurement\Network\OutboundOperationKind;
use App\Enums\Procurement\Network\TrafficClass;
use App\Repositories\Procurement\Network\NetworkPermitRepository;
use App\Repositories\Procurement\Network\OutboundOperationRepository;
use App\Repositories\Procurement\Network\SourceCatalogRepository;
use App\Services\Procurement\Network\SourceCatalogRegistry;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Platform\PlatformRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Platforms\JsonProcurement\JsonProcurementDefinition;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\NetworkFixtures;

pest()->group('source-catalog-lifecycle');

beforeEach(function (): void {
    Http::preventStrayRequests();
    NetworkFixtures::platform('eis');
    NetworkFixtures::platform('json');
    config()->set('platforms.definitions.json', JsonProcurementDefinition::class);
});

test('actual Definitions drive CLI sync and preserve legacy target identities adapter bindings and overrides', function (): void {
    $catalogs = app(SourceCatalogRegistry::class)->catalogs();
    expect(array_keys($catalogs))->toBe(['eis', 'json']);
    test()->artisan('platform:sync-catalog', ['--platform' => ['eis', 'json']])->assertSuccessful();
    foreach ($catalogs as $code => $catalog) {
        $platformId = DB::table('prc_platforms')->where('code', $code)->value('id');
        foreach ($catalog->targets as $target) {
            $row = DB::table('prc_source_targets')->where('platform_id', $platformId)->where('target_key', $target->key)->sole();
            expect($row->canonical_origin)->toBe($target->origin->value())
                ->and($row->capability)->toBe($target->capability)->and($row->retired_at)->toBeNull();
        }
    }
    expect($catalogs['eis']->targets['csv']->adapterContract->capability)->toBe('listing')
        ->and($catalogs['eis']->targets['card']->adapterContract->capability)->toBe('detail')
        ->and($catalogs['eis']->targets['okpd2']->capability)->toBe('reference_sync');
    $origin = CanonicalOrigin::fromUrl('https://zakupki.gov.ru');
    expect(isset($catalogs['eis']->targets['document.'.substr($origin->hash()->hex, 0, 16)]))->toBeTrue();
    $sync = app(SourceCatalogSync::class);
    $scope = $origin->budgetScopeKey();
    expect($sync->overrideBudget($scope, new OriginBudgetPolicy(12, 2, 3), 1, null, 'T45 override')->status)->toBe(NetworkChangeStatus::Applied);
    $before = DB::table('prc_source_targets')->orderBy('id')->get()->all();
    $budget = DB::table('prc_network_budgets')->where('scope_key', $scope)->sole();
    $events = DB::table('prc_process_transitions')->count();
    foreach ($sync->syncRegistered() as $report) {
        expect($report->changed())->toBeFalse();
    }
    expect(DB::table('prc_source_targets')->orderBy('id')->get()->all())->toEqual($before)
        ->and(DB::table('prc_network_budgets')->where('scope_key', $scope)->sole())->toEqual($budget)
        ->and(DB::table('prc_process_transitions')->count())->toBe($events);
    Http::assertNothingSent();
});

test('retired actual Definition target cannot authorize reserved or new permits and JSON stays available', function (): void {
    $sync = app(SourceCatalogSync::class);
    $sync->syncRegistered();
    $platform = DB::table('prc_platforms')->where('code', 'eis')->value('id');
    // Use the declared diagnostic profile; no adapter canary is needed for this catalog gate test.
    $target = new ProcessUuid(DB::table('prc_source_targets')->where('platform_id', $platform)->where('target_key', 'eis.diagnostic')->sole()->id);
    foreach (['prc_route_health', 'prc_route_target_health', 'prc_target_health'] as $table) {
        DB::table($table)->update(['state' => 'closed']);
    }
    $operations = app(OutboundOperationRepository::class);
    $owner = $operations->open(LedgerFixtures::uuid(), LedgerFixtures::uuid(), OutboundOperationKind::LegacyBridge,
        null, 'fixture.catalog', LedgerFixtures::hash('catalog'), ['acquisition:platform:eis'], 120, 120);
    $context = new OutboundExecutionContext($owner, fn ($handle) => $operations->renew($handle, 120), null, 120, 15);
    $endpoint = app(PlatformRegistry::class)->get(new PlatformCode('eis'))->endpoints->get('diagnostic');
    $makePlan = fn () => new OutboundRequestPlan(LedgerFixtures::uuid(), $target, 'GET', new SafeUrl($endpoint->url),
        $endpoint->profile, TrafficClass::Detail, IdempotencyClass::ReadOnly);
    $route = new NetworkRouteSelection(new ProcessUuid(DB::table('prc_network_routes')->sole()->id));
    $permits = app(NetworkPermitRepository::class);
    $ticket = $permits->register($makePlan(), $context, $route);
    expect($ticket)->toBeInstanceOf(NetworkDemandTicket::class);
    $permit = $permits->reserve($ticket, $context);
    expect($permit)->toBeInstanceOf(ScheduledNetworkPermit::class);
    expect($sync->retireTarget($target, 1, null, 'T45 retire')->status)->toBe(NetworkChangeStatus::Applied);
    expect($permits->claimSend($permit, $context))->toBeInstanceOf(TransportBlocked::class);
    expect($permits->register($makePlan(), $context, $route))->not->toBeInstanceOf(NetworkDemandTicket::class);
    expect(DB::table('prc_request_attempts')->count())->toBe(0)
        ->and(DB::table('prc_network_permits')->where('state', 'reserved')->count())->toBe(0);
    $repeat = $sync->syncRegistered(['eis'])[0];
    expect($repeat->retiredDeclared)->toBe(['eis.diagnostic']);
    $jsonId = DB::table('prc_platforms')->where('code', 'json')->value('id');
    expect(app(SourceCatalogRepository::class)->targetForCall($jsonId, null, 'detail', 'http://fixture-http:8080'))->not->toBeNull();
    Http::assertNothingSent();
});

test('platform catalogs cannot fall back to the obsolete outbound provider registration', function (): void {
    config()->set('outbound.catalog_providers', [NetworkFixtures::class]);
    expect(fn () => app(SourceCatalogRegistry::class)->providers())->toThrow(InvalidArgumentException::class, 'platforms.definitions');
    config()->set('platforms.definitions', []);
    expect(app(SourceCatalogRegistry::class)->catalogs())->toBe([]);
    expect(fn () => app(SourceCatalogSync::class)->syncRegistered(['eis']))->toThrow(InvalidArgumentException::class, 'No source catalog');
    expect(DB::table('prc_source_targets')->count())->toBe(0);
});
