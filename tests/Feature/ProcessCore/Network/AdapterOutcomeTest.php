<?php

declare(strict_types=1);

use App\Dto\Procurement\Network\AdapterContractRef;
use App\Dto\Procurement\Network\AdapterInspection;
use App\Dto\Procurement\Network\AdapterValidationCall;
use App\Dto\Procurement\Network\AdapterValidationClaim;
use App\Dto\Procurement\Network\HttpRouteBinding;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\ScheduledNetworkPermit;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Platform\SourceTargetDefinitions;
use App\Enums\Procurement\Network\AdapterVerdict;
use App\Enums\Procurement\Network\OutboundOperationKind;
use App\Enums\Procurement\Network\TrafficClass;
use App\Exceptions\Procurement\Network\OutboundOwnershipLost;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Models\User;
use App\Repositories\Procurement\Network\AdapterHealthRepository;
use App\Services\Procurement\Network\LegacyOutboundClient;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Proxy\RoutePolicy;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\Http\AdapterFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures as F;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;

beforeEach(fn () => F::clean());
afterEach(fn () => F::clean());

/** @return array{F, AdapterContractRef, RoutePolicy, LegacyOutboundClient} */
function adapterOutcomeFixture(WireFixture $wire): array
{
    $f = new F($wire);
    $ref = new AdapterContractRef($f->code, 'detail', '1', '1');
    app(SourceCatalogSync::class)->sync(new SourceTargetDefinitions($f->code, [
        new SourceTargetDefinition('card', 'detail', $f->url->origin, [$f->profile], adapterContract: $ref),
    ]));
    DB::table('prc_network_budgets')->update(['emission_interval_us' => 1000]);

    return [$f, $ref, new RoutePolicy([new HttpRouteBinding($f->route->routeId)]),
        app()->makeWith(LegacyOutboundClient::class, ['urls' => $wire->policy()])];
}

function adapterOutcomeCall(F $f, AdapterContractRef $ref, RoutePolicy $routes, string $input): AdapterValidationCall
{
    return new AdapterValidationCall('fixture.full_validation', DB::table('prc_platforms')->where('code', $f->code)->value('id'),
        $ref, LedgerFixtures::hash($input), $f->url, TrafficClass::Diagnostic, $routes, ['system', 'acquisition:platform:'.$f->code], 120);
}

function adapterOutcomeInspect(LegacyOutboundClient $client, AdapterValidationCall $call, int $tabs = 1,
    AdapterVerdict $verdict = AdapterVerdict::Accepted): Closure
{
    return function (OutboundExecutionContext $context) use ($client, $call, $tabs, $verdict): AdapterInspection {
        for ($tab = 0; $tab < $tabs; $tab++) {
            $response = $client->request('fixture.full_validation', $call->platformId, null, 'detail', $call->firstUrl,
                TrafficClass::Detail, $call->routes, $call->controls, 120, context: $context);
            expect($response->status())->toBe(200);
        }

        return new AdapterInspection('parsed', $verdict, 'fixture.full_contract',
            $verdict === AdapterVerdict::ContractFailure ? LedgerFixtures::hash('same_signature') : null);
    };
}

test('полная canary проверка двух вкладок имеет одного owner и один итоговый факт с реальными request IDs', function (): void {
    $wire = new WireFixture;
    try {
        [$f, $ref, $routes, $client] = adapterOutcomeFixture($wire);
        $call = adapterOutcomeCall($f, $ref, $routes, 'input1');
        $result = $client->validated($call, adapterOutcomeInspect($client, $call, 2));
        $health = DB::table('prc_adapter_health')->sole();
        $fact = DB::table('prc_observations')->where('kind', 'adapter_validation')->sole();
        $details = json_decode($fact->details, true, flags: JSON_THROW_ON_ERROR);
        expect($result->value)->toBe('parsed')->and($health->state)->toBe('healthy')
            ->and($health->probe_token)->toBeNull()->and(DB::table('prc_outbound_operations')->count())->toBe(1)
            ->and(DB::table('prc_outbound_operations')->sole()->state)->toBe('closed')
            ->and($details['request_ids'])->toHaveCount(2)
            ->and($details['request_ids'])->toBe(DB::table('prc_request_attempts')->orderBy('request_seq')->pluck('request_id')->all())
            ->and($fact->request_id)->toBe($details['request_ids'][1])->and($wire->receipts())->toHaveCount(2);
        app(ProcessTransaction::class)->run(fn () => app(AdapterHealthRepository::class)->assertReceipt($result->receipt));
    } finally {
        $wire->stop();
    }
})->group('t05', 'p26');

test('повторы одного input не закрывают адаптер а три разных input с общей сигнатурой требуют audited reset', function (): void {
    $wire = new WireFixture;
    try {
        [$f, $ref, $routes, $client] = adapterOutcomeFixture($wire);
        foreach (['bad1', 'bad1', 'bad1', 'bad2', 'bad3'] as $index => $input) {
            $call = adapterOutcomeCall($f, $ref, $routes, $input);
            $client->validated($call, adapterOutcomeInspect($client, $call, verdict: AdapterVerdict::ContractFailure));
            expect(DB::table('prc_adapter_health')->sole()->state)->toBe($index === 4 ? 'held' : 'suspect');
        }
        $call = adapterOutcomeCall($f, $ref, $routes, 'blocked');
        expect(fn () => $client->validated($call, adapterOutcomeInspect($client, $call)))->toThrow(OutboundUnavailable::class)
            ->and($wire->receipts())->toHaveCount(5)
            ->and(DB::table('prc_process_incidents')->where('kind', 'adapter_contract_held')->count())->toBe(1);
        $repository = app(AdapterHealthRepository::class);
        $health = DB::table('prc_adapter_health')->sole();
        $actor = User::factory()->create();
        $tx = app(ProcessTransaction::class);
        expect($tx->run(fn () => $repository->reset($ref, $health->state_version - 1, $actor->id, 'Исправлен адаптер'))->status->value)->toBe('version_conflict');
        expect($tx->run(fn () => $repository->reset($ref, $health->state_version, $actor->id, 'Исправлен адаптер'))->status->value)->toBe('applied')
            ->and(DB::table('prc_adapter_health')->sole()->state)->toBe('validating')
            ->and(DB::table('prc_observations')->where('kind', 'adapter_validation_reset')->count())->toBe(1);
        $client->validated($call, adapterOutcomeInspect($client, $call));
        expect(DB::table('prc_adapter_health')->sole()->state)->toBe('healthy');
    } finally {
        $wire->stop();
    }
})->group('t05', 'p26');

test('разрешённый redirect полной canary проходит отдельные permits без второго transport retry', function (): void {
    $wire = new WireFixture;
    try {
        [$f, $ref, $routes, $client] = adapterOutcomeFixture($wire);
        $wire->respond('/start', '', 302, ['Location' => '/ok']);
        $call = adapterOutcomeCall($f, $ref, $routes, 'redirected');
        $call = new AdapterValidationCall($call->callerCode, $call->platformId, $ref, $call->inputHash,
            $wire->url('/start'), TrafficClass::Diagnostic, $routes, $call->controls, 120);
        $result = $client->validated($call, adapterOutcomeInspect($client, $call));
        expect($wire->receipts())->toHaveCount(2)
            ->and(DB::table('prc_network_permits')->where('state', 'released')->count())->toBe(2)
            ->and(DB::table('prc_outbound_operations')->count())->toBe(1)
            ->and($result->receipt->requestIds)->toHaveCount(1)
            ->and($result->receipt->requestIds[0]->value)->toBe(DB::table('prc_request_attempts')->where('status', 200)->sole()->request_id)
            ->and(DB::table('prc_adapter_health')->sole()->state)->toBe('healthy');
    } finally {
        $wire->stop();
    }
})->group('t05', 'p26');

test('partial input не подтверждает systemic failure или full canary success но сохраняет разрешённую provenance', function (): void {
    $wire = new WireFixture;
    try {
        [$f, $ref, $routes, $client] = adapterOutcomeFixture($wire);
        foreach (['partial1', 'partial2', 'partial3'] as $input) {
            $call = adapterOutcomeCall($f, $ref, $routes, $input);
            $result = $client->validated($call, adapterOutcomeInspect($client, $call, verdict: AdapterVerdict::Partial));
            expect($result->receipt->verdict)->toBe(AdapterVerdict::Partial)
                ->and(DB::table('prc_adapter_health')->sole()->state)->toBe('validating');
            app(ProcessTransaction::class)->run(fn () => app(AdapterHealthRepository::class)->assertReceipt($result->receipt));
        }
        expect(DB::table('prc_process_incidents')->where('kind', 'adapter_contract_held')->count())->toBe(0)
            ->and(DB::table('prc_observations')->where('kind', 'adapter_validation')->count())->toBe(3);
    } finally {
        $wire->stop();
    }
})->group('t05', 'p26');

test('late полный parser result остаётся stale фактом после commit и не повышает adapter health', function (): void {
    $wire = new WireFixture;
    try {
        [$f, $ref, $routes, $client] = adapterOutcomeFixture($wire);
        $call = adapterOutcomeCall($f, $ref, $routes, 'late');
        expect(fn () => $client->validated($call, function (OutboundExecutionContext $context) use ($client, $call): AdapterInspection {
            $inspection = adapterOutcomeInspect($client, $call)($context);
            DB::table('prc_outbound_operations')->where('id', $context->lease()->id->value)
                ->update(['lease_until' => DB::raw("clock_timestamp() - interval '1 second'")]);

            return $inspection;
        }))->toThrow(OutboundOwnershipLost::class);
        $fact = DB::table('prc_observations')->where('kind', 'adapter_validation')->sole();
        expect($fact->stale)->toBeTrue()
            ->and(DB::table('prc_observation_applications')->where('observation_id', $fact->observation_id)->count())->toBe(1)
            ->and(DB::table('prc_adapter_health')->sole()->state)->toBe('validating');
    } finally {
        $wire->stop();
    }
})->group('t05', 'p26');

test('semantic rejection отзывает старых owners до canary и восстанавливает ramp только с capacity два', function (AdapterVerdict $rejection): void {
    $wire = new WireFixture;
    try {
        [$f, $ref, $routes, $client] = adapterOutcomeFixture($wire);
        foreach (range(1, 3) as $index) {
            $call = adapterOutcomeCall($f, $ref, $routes, 'warmup'.$index);
            $client->validated($call, adapterOutcomeInspect($client, $call));
        }
        DB::table('prc_network_budgets')->update(['max_inflight' => 8]);
        $repository = app(AdapterHealthRepository::class);
        $tx = app(ProcessTransaction::class);
        $owner = function (bool $mustAdmit = true) use ($f, $ref, $repository, $tx): OutboundExecutionContext {
            $context = $f->context();
            DB::table('prc_outbound_operations')->where('id', $context->lease()->id->value)->update([
                'platform_id' => DB::table('prc_platforms')->where('code', $f->code)->value('id'),
            ]);
            $claim = $tx->run(fn () => $repository->admit($ref, $context));
            if ($mustAdmit) {
                expect($claim)->toBeInstanceOf(AdapterValidationClaim::class);
                $context->bindAdapter($claim);
            } else {
                expect($claim)->toBeInstanceOf(TransportDeferred::class);
            }

            return $context;
        };
        $a = $owner();
        $b = $owner();
        $c = $owner();
        foreach ([$a, $b] as $context) {
            $client->request('fixture.full_validation', DB::table('prc_platforms')->where('code', $f->code)->value('id'), null,
                'detail', $f->url, TrafficClass::Detail, $routes, ['system', 'acquisition:platform:'.$f->code], 120, context: $context);
        }
        $plan = $f->plan();
        $plan = new OutboundRequestPlan($plan->physicalRequestId, $plan->targetId, $plan->method,
            $plan->url, $plan->profile, $plan->trafficClass, $plan->idempotency, adapterClaim: $c->adapterClaim());
        $ticket = $f->repo->register($plan, $c, $f->route);
        $permit = $f->repo->reserve($ticket, $c);
        expect($permit)->toBeInstanceOf(ScheduledNetworkPermit::class);
        $oldEpoch = DB::table('prc_adapter_health')->sole()->health_epoch;
        $failed = $tx->run(fn () => $repository->observe($a, LedgerFixtures::hash('failed'),
            new AdapterInspection('rejected', $rejection, 'fixture.schema', $rejection === AdapterVerdict::ContractFailure ? LedgerFixtures::hash('signature') : null)));
        expect($failed->stale)->toBeFalse()->and($failed->epoch)->toBe($oldEpoch)
            ->and(DB::table('prc_adapter_health')->sole()->health_epoch)->toBe($oldEpoch + 1)
            ->and(DB::table('prc_adapter_health')->sole()->state)->toBe('suspect');
        $late = $tx->run(fn () => $repository->observe($b, LedgerFixtures::hash('late'),
            new AdapterInspection('parsed', AdapterVerdict::Accepted, 'fixture.full_contract')));
        expect($late->stale)->toBeTrue()->and(DB::table('prc_adapter_health')->sole()->state)->toBe('suspect');
        $call = adapterOutcomeCall($f, $ref, $routes, 'new_canary');
        $client->validated($call, adapterOutcomeInspect($client, $call));
        expect(DB::table('prc_adapter_health')->sole()->state)->toBe('healthy')
            ->and($f->repo->claimSend($permit, $c))->toBeInstanceOf(TransportBlocked::class)
            ->and(DB::table('prc_request_attempts')->where('request_id', $plan->physicalRequestId->value)->count())->toBe(0)
            ->and($wire->receipts())->toHaveCount(6);
        $owner();
        $owner();
        $owner(false);
    } finally {
        $wire->stop();
    }
})->with([AdapterVerdict::ContractFailure, AdapterVerdict::HandlerFault])->group('t05', 't35', 'p26');

test('явный reset начинает новую evidence generation а admission epoch её не стирает', function (): void {
    $fixture = new AdapterFixtures;
    try {
        foreach (['one', 'two', 'three'] as $input) {
            $fixture->validate($input, AdapterVerdict::ContractFailure);
        }
        $repository = app(AdapterHealthRepository::class);
        $tx = app(ProcessTransaction::class);
        $health = DB::table('prc_adapter_health')->sole();
        $actor = User::factory()->create();
        $tx->run(fn () => $repository->reset($fixture->contract, $health->state_version, $actor->id, 'Исправлен контракт'));
        $fixture->validate('one', AdapterVerdict::ContractFailure);
        $fixture->validate('two', AdapterVerdict::ContractFailure);
        expect(DB::table('prc_adapter_health')->sole()->state)->toBe('suspect');
        $fixture->validate('accepted');
        $epoch = DB::table('prc_adapter_health')->sole()->health_epoch;
        $fixture->validate('handler', AdapterVerdict::HandlerFault);
        expect(DB::table('prc_adapter_health')->sole()->health_epoch)->toBe($epoch + 1);
        $fixture->validate('three', AdapterVerdict::ContractFailure);
        expect(DB::table('prc_adapter_health')->sole()->state)->toBe('held')
            ->and(DB::table('prc_observations')->where('kind', 'adapter_validation_reset')->count())->toBe(1);
    } finally {
        $fixture->close();
    }
})->group('t05', 'p26');

test('reaper мёртвого canary отзывает поздний owner но сохраняет уже применённые contract failures', function (): void {
    $wire = new WireFixture;
    try {
        [$f, $ref, $routes, $client] = adapterOutcomeFixture($wire);
        foreach (['one', 'two'] as $input) {
            $call = adapterOutcomeCall($f, $ref, $routes, $input);
            $client->validated($call, adapterOutcomeInspect($client, $call, verdict: AdapterVerdict::ContractFailure));
        }
        $context = $f->context(kind: OutboundOperationKind::Probe, probeToken: LedgerFixtures::uuid());
        DB::table('prc_outbound_operations')->where('id', $context->lease()->id->value)->update([
            'platform_id' => DB::table('prc_platforms')->where('code', $f->code)->value('id'),
            'probe_token' => $context->lease()->ownerToken->value,
        ]);
        $repository = app(AdapterHealthRepository::class);
        $tx = app(ProcessTransaction::class);
        $claim = $tx->run(fn () => $repository->admit($ref, $context));
        $context->bindAdapter($claim);
        DB::table('prc_outbound_operations')->where('id', $context->lease()->id->value)->update(['lease_until' => DB::raw("clock_timestamp() - interval '1 second'")]);
        expect($tx->run(fn () => $repository->reap()))->toBe(1)
            ->and(DB::table('prc_adapter_health')->sole()->health_epoch)->toBe($claim->epoch + 1);
        $call = adapterOutcomeCall($f, $ref, $routes, 'three');
        $client->validated($call, adapterOutcomeInspect($client, $call, verdict: AdapterVerdict::ContractFailure));
        expect(DB::table('prc_adapter_health')->sole()->state)->toBe('held')
            ->and(DB::table('prc_observations')->where('kind', 'adapter_validation')->where('stale', false)->count())->toBe(3);
    } finally {
        $wire->stop();
    }
})->group('t05', 'p26');
