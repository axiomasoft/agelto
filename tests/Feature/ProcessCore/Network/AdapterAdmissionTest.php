<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\AdapterValidation\AdapterContractRef;
use App\Dto\Procurement\Network\AdapterValidation\AdapterValidationClaim;
use App\Dto\Procurement\Network\Control\OutboundExecutionContext;
use App\Dto\Procurement\Network\Outcome\TransportBlocked;
use App\Dto\Procurement\Network\Outcome\TransportDeferred;
use App\Dto\Procurement\Platform\Definition\SourceTargetDefinition;
use App\Dto\Procurement\Platform\Definition\SourceTargetDefinitions;
use App\Enums\Procurement\Network\OutboundOperationKind;
use App\Repositories\Procurement\Network\AdapterHealthRepository;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\HistoricalNetworkFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;
use Tests\Support\ProcessCore\LedgerFixtures;

beforeEach(fn () => F::clean());
afterEach(fn () => F::clean());

/** @return array{F, AdapterContractRef} */
function adapterAdmissionFixture(): array
{
    $f = new F;
    $ref = new AdapterContractRef($f->code, 'detail', '1', '1');
    app(SourceCatalogSync::class)->sync(new SourceTargetDefinitions($f->code, [
        new SourceTargetDefinition('card', 'detail', $f->url->origin, [$f->profile], adapterContract: $ref),
    ]));
    HistoricalNetworkFixtures::synchronize();

    return [$f, $ref];
}

function adapterAdmissionOwner(F $f, bool $probe): OutboundExecutionContext
{
    $context = $f->context(kind: $probe ? OutboundOperationKind::Probe : OutboundOperationKind::LegacyBridge,
        probeToken: $probe ? LedgerFixtures::uuid() : null);
    DB::table('prc_outbound_operations')->where('id', $context->lease()->id->value)->update([
        'platform_id' => DB::table('prc_source_targets')->where('id', $f->target->value)->value('platform_id'),
        'probe_token' => $probe ? $context->lease()->ownerToken->value : null,
    ]);

    return $context;
}

test('validating adapter допускает ровно одного owner всей проверки и не создаёт HTTP попыток', function (): void {
    [$f, $ref] = adapterAdmissionFixture();
    $first = adapterAdmissionOwner($f, true);
    $second = adapterAdmissionOwner($f, true);
    $repository = app(AdapterHealthRepository::class);
    $tx = app(ProcessTransaction::class);
    $claim = $tx->run(fn () => $repository->admit($ref, $first));
    expect($claim)->toBeInstanceOf(AdapterValidationClaim::class)->and($claim->canary)->toBeTrue();
    $first->bindAdapter($claim);
    expect($tx->run(fn () => $repository->admit($ref, $second)))->toBeInstanceOf(TransportDeferred::class)
        ->and(DB::table('prc_outbound_operations')->whereNotNull('adapter_health_id')->count())->toBe(1)
        ->and(DB::table('prc_request_attempts')->count())->toBe(0)
        ->and(fn () => $first->bindAdapter($claim))->toThrow(LogicException::class);
})->group('network-admission-probes');

test('ordinary owner не обходит initial validation claim и catalog version binding', function (): void {
    [$f, $ref] = adapterAdmissionFixture();
    $ordinary = adapterAdmissionOwner($f, false);
    $repository = app(AdapterHealthRepository::class);
    expect(app(ProcessTransaction::class)->run(fn () => $repository->admit($ref, $ordinary)))->toBeInstanceOf(TransportBlocked::class)
        ->and(DB::table('prc_outbound_operations')->whereNotNull('adapter_health_id')->count())->toBe(0);
    $probe = adapterAdmissionOwner($f, true);
    $foreignVersion = new AdapterContractRef($ref->platformCode, 'detail', '2', '1');
    expect(app(ProcessTransaction::class)->run(fn () => $repository->admit($foreignVersion, $probe)))->toBeInstanceOf(TransportBlocked::class)
        ->and(DB::table('prc_adapter_health')->sole()->probe_token)->toBeNull()
        ->and(DB::table('prc_request_attempts')->count())->toBe(0);
})->group('network-admission-probes');
