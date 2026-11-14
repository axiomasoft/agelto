<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\AdapterContractRef;
use App\Dto\Procurement\Network\AdapterValidationClaim;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Network\TransferBudget;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Platform\PlatformCode;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\TrafficClass;
use App\Repositories\Procurement\Network\AdapterHealthRepository;
use App\Repositories\Procurement\Network\SourceCatalogRepository;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Network\UrlPolicy;
use App\Services\Procurement\Platform\PlatformRegistry;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Proxyma\CallerOutbound;
use App\Services\Procurement\Proxyma\RuntimeCaller;
use Illuminate\Support\Facades\DB;
use Proxyma\Application\Admission\ClaimSend;
use Proxyma\Application\Admission\ReservationService;
use Proxyma\Contracts\CatalogCommands;
use Proxyma\Contracts\CatalogQueries;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Catalog\ActorRef;
use Proxyma\Domain\Catalog\CatalogMutationActor;
use Proxyma\Domain\Catalog\RetireCatalogEntityCommand;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\PackageSchema;
use Tests\Support\Platforms\JsonProcurement\JsonProcurementDefinition;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\NetworkFixtures;
use Tests\Support\ProcessCore\ProxymaCallerFixtures;

pest()->group('source-catalog-lifecycle', 'catalog-retirement');

beforeEach(function (): void {
    PermitFixtures::clean();
    if (! DB::table('prc_platforms')->where('code', 'eis')->exists()) {
        NetworkFixtures::platform('eis');
    }
    if (! DB::table('prc_platforms')->where('code', 'json')->exists()) {
        NetworkFixtures::platform('json');
    }
    config()->set('platforms.definitions.json', JsonProcurementDefinition::class);
});

function reserveRealDefinitionPermit(OutboundRequestPlan $plan, OutboundExecutionContext $context): array
{
    return ProxymaCallerFixtures::reserve($plan, $context);
}

test('retirement реального Definition отклоняет reserved и новый consumer request, а JSON остаётся доступен', function (): void {
    $wire = new WireFixture;
    try {
        $sync = app(SourceCatalogSync::class);
        $sync->syncRegistered();
        foreach ($sync->syncRegistered(['eis']) as $report) {
            expect($report->changed())->toBeFalse();
        }
        $fixture = new PermitFixtures($wire);
        $platformId = (int) DB::table('prc_platforms')->where('code', 'eis')->value('id');
        $source = DB::table('prc_source_targets')->where('platform_id', $platformId)->where('target_key', 'card')->sole();
        $policy = json_decode($source->endpoint_policy, true, flags: JSON_THROW_ON_ERROR);
        $contract = AdapterContractRef::fromArray($policy['adapter_contract']);
        DB::table('prc_adapter_health')->where('platform_id', $platformId)->where('capability', $contract->capability)
            ->where('adapter_version', $contract->adapterVersion)->where('contract_version', $contract->contractVersion)->update(['state' => 'healthy']);
        ProxymaCallerFixtures::install($wire);
        $target = new ProcessUuid($source->id);
        $endpoint = app(PlatformRegistry::class)->get(new PlatformCode('eis'))->endpoints->get('card');
        $context = $fixture->context();
        DB::table('prc_outbound_operations')->where('id', $context->lease()->id->value)->update(['platform_id' => $platformId]);
        $claim = app(ProcessTransaction::class)->run(fn () => app(AdapterHealthRepository::class)->admit($contract, $context));
        expect($claim)->toBeInstanceOf(AdapterValidationClaim::class);
        $context->bindAdapter($claim);
        $plan = new OutboundRequestPlan(LedgerFixtures::uuid(), $target, 'GET', new SafeUrl($endpoint->url), $endpoint->profile,
            TrafficClass::Detail, IdempotencyClass::ReadOnly, adapterClaim: $claim);
        [$input, $permit, $sealed, $operation, $nativeTarget] = reserveRealDefinitionPermit($plan, $context);
        $actor = new CatalogMutationActor(new ActorRef('worker', 'fixture-caller'), 'fixture Definition retirement', $nativeTarget->revision, Uuid::random()->value);
        app(CatalogCommands::class)->retireTarget(new RetireCatalogEntityCommand($nativeTarget->id, TenantKey::system(), $actor));
        $retired = app(RuntimeCaller::class)->run($plan, $context,
            fn () => app(CatalogQueries::class)->findTarget(TenantKey::system(), $nativeTarget->id));
        $urlPolicy = new UrlPolicy([$plan->url->origin], [$plan->url->origin->host => ['127.0.0.1']]);
        $claimDenied = null;
        try {
            app(RuntimeCaller::class)->run($plan, $context, fn () => app(ClaimSend::class)->claim($permit, $input, $sealed));
        } catch (AdmissionRejected $denied) {
            $claimDenied = $denied;
        }
        app(RuntimeCaller::class)->run($plan, $context,
            fn () => app(ReservationService::class)->cancel(TenantKey::system(), $operation->operationId, $permit->permitId));
        $second = $fixture->context();
        DB::table('prc_outbound_operations')->where('id', $second->lease()->id->value)->update(['platform_id' => $platformId]);
        $new = app(CallerOutbound::class)->execute(new OutboundRequestPlan(LedgerFixtures::uuid(), $target, 'GET',
            new SafeUrl($endpoint->url), $endpoint->profile, TrafficClass::Detail, IdempotencyClass::ReadOnly), $second,
            ProxymaCallerFixtures::gate($fixture->route->routeId->value), $urlPolicy, new TransferBudget(262144, 262144));
        $jsonId = DB::table('prc_platforms')->where('code', 'json')->value('id');

        expect($retired?->isRetired())->toBeTrue()
            ->and($claimDenied)->toBeInstanceOf(AdmissionRejected::class)
            ->and(DB::table(PackageSchema::tables()['permits'])->where('id', $permit->permitId->value)->value('state'))->toBe('cancelled_before_send')
            ->and(DB::table(PackageSchema::tables()['reservations'])->where('permit_id', $permit->permitId->value)->where('state', 'reserved')->count())->toBe(0)
            ->and($new)->toBeInstanceOf(TransportBlocked::class)
            ->and(DB::table('prc_request_attempts')->count())->toBe(0)
            ->and(DB::table(PackageSchema::tables()['attempts'])->count())->toBe(0)
            ->and($wire->receipts())->toBe([])
            ->and(app(SourceCatalogRepository::class)->targetForCall($jsonId, null, 'detail', 'http://fixture-http:8080'))->not->toBeNull();
    } finally {
        $wire->stop();
    }
});
