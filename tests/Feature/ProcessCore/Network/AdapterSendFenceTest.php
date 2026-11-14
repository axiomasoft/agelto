<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\AdapterContractRef;
use App\Dto\Procurement\Network\AdapterValidationClaim;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\TransferBudget;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Platform\SourceTargetDefinitions;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\OutboundOperationKind;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Proxyma\CallerOutbound as OutboundExecutor;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\HistoricalNetworkFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ProxymaCallerFixtures;

beforeEach(fn () => F::clean());
afterEach(fn () => F::clean());

/** @return array{F, object, AdapterContractRef} */
function boundAdapterPermit(?WireFixture $wire = null): array
{
    $f = new F($wire);
    $contract = new AdapterContractRef($f->code, 'detail', '1', '1');
    app(SourceCatalogSync::class)->sync(new SourceTargetDefinitions($f->code, [
        new SourceTargetDefinition('card', 'detail', $f->url->origin, [$f->profile], adapterContract: $contract),
    ]));
    HistoricalNetworkFixtures::synchronize();

    return [$f, DB::table('prc_adapter_health')->sole(), $contract];
}

/** Explicit prior validation ownership is fixture setup; every tested send still uses real fences. */
function attachAdapterOwner(F $f, object $health, AdapterContractRef $contract, $context, bool $canary): OutboundRequestPlan
{
    DB::table('prc_outbound_operations')->where('id', $context->lease()->id->value)
        ->update(['adapter_health_id' => $health->id, 'adapter_health_epoch' => 1, 'platform_id' => $health->platform_id]);
    $claim = new AdapterValidationClaim(new ProcessUuid($health->id), 1,
        $contract, $context->lease()->id, $canary);
    $original = $f->plan();

    return new OutboundRequestPlan($original->physicalRequestId, $original->targetId, $original->method,
        $original->url, $original->profile, $original->trafficClass, $original->idempotency, adapterClaim: $claim);
}

test('bound target отвергает пропущенный validator claim до demand и physical attempt', function (): void {
    $wire = new WireFixture;
    try {
        [$f, $health] = boundAdapterPermit($wire);
        ProxymaCallerFixtures::install($wire);
        $context = $f->context();
        DB::table('prc_outbound_operations')->where('id', $context->lease()->id->value)->update(['platform_id' => $health->platform_id]);
        $result = app(OutboundExecutor::class)->execute($f->plan(), $context, $f->gate(), $wire->policy(), new TransferBudget(65536, 65536));
        expect($result)->toBeInstanceOf(TransportBlocked::class)
            ->and(DB::table('pxm_attempts')->count())->toBe(0)
            ->and($wire->receipts())->toBe([]);
    } finally {
        $wire->stop();
    }
})->group('provider-send-fencing');

test('hold адаптера после выдачи owner claim запрещает новый send intent', function (): void {
    $wire = new WireFixture;
    try {
        [$f, $health, $contract] = boundAdapterPermit($wire);
        ProxymaCallerFixtures::install($wire);
        DB::table('prc_adapter_health')->where('id', $health->id)->update(['state' => 'healthy']);
        $context = $f->context();
        $plan = attachAdapterOwner($f, $health, $contract, $context, false);
        DB::table('prc_adapter_health')->where('id', $health->id)->update(['state' => 'held', 'health_epoch' => 2, 'state_version' => 2]);
        expect(app(OutboundExecutor::class)->execute($plan, $context, $f->gate(), $wire->policy(), new TransferBudget(65536, 65536)))
            ->toBeInstanceOf(TransportBlocked::class)
            ->and(DB::table('pxm_attempts')->count())->toBe(0)
            ->and($wire->receipts())->toBe([]);
    } finally {
        $wire->stop();
    }
})->group('provider-send-fencing');

test('adapter-only canary использует permit но HTTP 200 не объявляет parser healthy', function (): void {
    $wire = new WireFixture;
    try {
        [$f, $health, $contract] = boundAdapterPermit($wire);
        ProxymaCallerFixtures::install($wire);
        $token = LedgerFixtures::uuid();
        $context = $f->context(kind: OutboundOperationKind::Probe, probeToken: $token);
        $plan = attachAdapterOwner($f, $health, $contract, $context, true);
        DB::table('prc_adapter_health')->where('id', $health->id)->update([
            'probe_token' => $token->value, 'probe_until' => $context->lease()->deadlineAt,
        ]);
        $result = app(OutboundExecutor::class)->execute($plan, $context, $f->gate(), $wire->policy(), new TransferBudget(65536, 65536));
        expect($result)->toBeInstanceOf(TransportSucceeded::class)
            ->and($result->physicalRequestId?->value)->toBe($plan->physicalRequestId->value)
            ->and($wire->receipts())->toHaveCount(1)
            ->and(DB::table('pxm_attempts')->count())->toBe(1)
            ->and(DB::table('prc_adapter_health')->sole()->state)->toBe('validating')
            ->and(DB::table('prc_observations')->where('kind', 'adapter_validation')->count())->toBe(0);
    } finally {
        $wire->stop();
    }
})->group('provider-send-fencing');
