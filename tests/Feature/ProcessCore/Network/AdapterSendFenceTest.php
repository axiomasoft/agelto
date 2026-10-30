<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\AdapterContractRef;
use App\Dto\Procurement\Network\AdapterValidationClaim;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\ScheduledNetworkPermit;
use App\Dto\Procurement\Network\TransferBudget;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Platform\SourceTargetDefinitions;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\OutboundOperationKind;
use App\Services\Procurement\Network\OutboundExecutor;
use App\Services\Procurement\Network\SourceCatalogSync;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;

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
    [$f] = boundAdapterPermit();
    $context = $f->context();
    expect($f->repo->register($f->plan(), $context, $f->route))->toBeInstanceOf(TransportBlocked::class)
        ->and(DB::table('prc_network_demands')->count())->toBe(0)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
})->group('t35', 'p26');

test('hold адаптера между reserve и claimSend запрещает send intent', function (): void {
    [$f, $health, $contract] = boundAdapterPermit();
    DB::table('prc_adapter_health')->where('id', $health->id)->update(['state' => 'healthy']);
    $context = $f->context();
    $plan = attachAdapterOwner($f, $health, $contract, $context, false);
    $ticket = $f->repo->register($plan, $context, $f->route);
    $permit = $f->repo->reserve($ticket, $context);
    expect($permit)->toBeInstanceOf(ScheduledNetworkPermit::class);
    DB::table('prc_adapter_health')->where('id', $health->id)->update(['state' => 'held', 'health_epoch' => 2, 'state_version' => 2]);
    expect($f->repo->claimSend($permit, $context))->toBeInstanceOf(TransportBlocked::class)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
})->group('t35', 'p26');

test('adapter-only canary использует permit но HTTP 200 не объявляет parser healthy', function (): void {
    $wire = new WireFixture;
    try {
        [$f, $health, $contract] = boundAdapterPermit($wire);
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
            ->and(DB::table('prc_request_attempts')->value('status'))->toBe(200)
            ->and(DB::table('prc_adapter_health')->sole()->state)->toBe('validating')
            ->and(DB::table('prc_observations')->where('kind', 'adapter_validation')->count())->toBe(0);
    } finally {
        $wire->stop();
    }
})->group('t35', 'p26');
