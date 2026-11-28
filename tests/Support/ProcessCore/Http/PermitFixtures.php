<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore\Http;

use App\Dto\Procurement\Network\Control\NetworkRouteSelection;
use App\Dto\Procurement\Network\Control\OutboundExecutionContext;
use App\Dto\Procurement\Network\Control\OutboundOperationHandle;
use App\Dto\Procurement\Network\Control\OutboundRequestPlan;
use App\Dto\Procurement\Network\Http\SafeUrl;
use App\Dto\Procurement\Platform\Definition\OriginBudgetPolicy;
use App\Dto\Procurement\Platform\Definition\RequestProfile;
use App\Dto\Procurement\Platform\Definition\SourceTargetDefinition;
use App\Dto\Procurement\Platform\Definition\SourceTargetDefinitions;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\OutboundOperationKind;
use App\Enums\Procurement\Network\TrafficClass;
use App\Repositories\Procurement\Network\OutboundOperationRepository;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use App\Services\Procurement\Proxyma\Caller\CallerGate;
use App\Services\Procurement\Proxyma\Caller\CallerSendFacts;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\HistoricalNetworkFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\NetworkFixtures;
use Tests\Support\ProcessCore\ProxymaCallerFixtures;
use Tests\Support\ProcessCore\ProxymaFixtures;
use Tests\Support\TestEnvironmentGuard;

/** Real owners, catalog and PostgreSQL. Only health readiness/time facts are fixture setup. */
final class PermitFixtures
{
    private ?WireFixture $wireFixture;

    public readonly string $code;

    public readonly ProcessUuid $target;

    public readonly NetworkRouteSelection $route;

    public readonly RequestProfile $profile;

    public readonly SafeUrl $url;

    public function __construct(?WireFixture $wire = null, bool $tls = false)
    {
        $this->wireFixture = $wire;
        $this->code = 'permit_'.bin2hex(random_bytes(6));
        NetworkFixtures::platform($this->code);
        $this->url = $wire?->url('/ok', $tls) ?? new SafeUrl('https://example.test/ok');
        $this->profile = TransportFixtures::profile(['total_seconds' => 4]);
        app(SourceCatalogSync::class)->sync(new SourceTargetDefinitions($this->code, [
            new SourceTargetDefinition('card', 'detail', $this->url->origin, [$this->profile], new OriginBudgetPolicy(600, 1, 2)),
        ]));
        HistoricalNetworkFixtures::synchronize();
        $this->target = new ProcessUuid(DB::table('prc_source_targets')->where('target_key', 'card')->whereNull('retired_at')
            ->where('platform_id', DB::table('prc_platforms')->where('code', $this->code)->value('id'))->sole()->id);
        $this->route = new NetworkRouteSelection(new ProcessUuid(DB::table('prc_network_routes')->sole()->id));
        foreach (['prc_route_health', 'prc_route_target_health', 'prc_target_health'] as $table) {
            DB::table($table)->update(['state' => 'closed']);
        }
        // Test scaling keeps the same GCRA inequalities; production defaults remain 15/min.
        DB::table('prc_network_budgets')->update(['emission_interval_us' => 100_000]);
    }

    public static function clean(): void
    {
        TestEnvironmentGuard::assertBootstrapTestDatabase(app());
        if (getenv('PROCESS_CORE_TEST') !== '1') {
            throw new \LogicException('Permit fixtures require isolated core rig.');
        }
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        ProxymaFixtures::resetAuthority();
        DB::table('prc_observation_applications')->whereIn('observation_id', DB::table('prc_observations')->select('observation_id')->whereIn('kind', ['adapter_validation', 'adapter_validation_reset']))->delete();
        DB::table('prc_observations')->whereIn('kind', ['adapter_validation', 'adapter_validation_reset'])->delete();
        DB::statement('TRUNCATE prc_adapter_health, prc_network_routes, prc_network_route_profiles, prc_source_targets, prc_outbound_operations CASCADE');
        DB::table('prc_control_scopes')->whereIn('scope_key', ['system', 'monitoring'])->update(['desired_mode' => 'enabled']);
    }

    public function context(?\Closure $checkpoint = null, OutboundOperationKind $kind = OutboundOperationKind::LegacyBridge, ?ProcessUuid $probeToken = null): OutboundExecutionContext
    {
        $owner = app(ProcessTransaction::class)->run(fn () => app(OutboundOperationRepository::class)->open(
            LedgerFixtures::uuid(), LedgerFixtures::uuid(), $kind, null, 'fixture.permits', LedgerFixtures::hash('policy'),
            ['acquisition:platform:'.$this->code], 120, 120, $probeToken,
        ));

        return new OutboundExecutionContext($owner, fn ($handle) => app(ProcessTransaction::class)->run(fn () => app(OutboundOperationRepository::class)->renew($handle, 120)), $checkpoint, 120, 15);
    }

    public static function importContext(string $operationId): OutboundExecutionContext
    {
        $row = DB::table('prc_outbound_operations')->where('id', $operationId)->sole();
        $lease = new OutboundOperationHandle(new ProcessUuid($row->id), OutboundOperationKind::from($row->kind),
            (int) $row->generation, new ProcessUuid($row->owner_token),
            new \DateTimeImmutable($row->lease_until), new \DateTimeImmutable($row->deadline_at));

        return new OutboundExecutionContext($lease, static fn () => $lease, static function (): void {}, 60, 15);
    }

    public function gate(): CallerGate
    {
        if (! CallerSendFacts::active()) {
            ProxymaCallerFixtures::install($this->wireFixture ?? throw new \LogicException('Wire fixture required for execution.'));
        }

        return ProxymaCallerFixtures::gate($this->route->routeId->value);
    }

    public function plan(TrafficClass $class = TrafficClass::Detail, ?SafeUrl $url = null, ?ProcessUuid $physicalId = null): OutboundRequestPlan
    {
        return new OutboundRequestPlan($physicalId ?? LedgerFixtures::uuid(), $this->target, 'GET', $url ?? $this->url, $this->profile, $class, IdempotencyClass::ReadOnly);
    }

    public function history(OutboundExecutionContext $context, int $count, int $secondsAgo = 10): void
    {
        $origin = DB::table('prc_network_budgets')->where('scope_key', $this->url->origin->budgetScopeKey())->sole()->id;
        for ($i = 0; $i < $count; $i++) {
            $demand = LedgerFixtures::uuid()->value;
            $physical = LedgerFixtures::uuid()->value;
            $when = (new \DateTimeImmutable('-'.$secondsAgo.' seconds'))->format('Y-m-d H:i:s.uP');
            DB::table('prc_network_demands')->insert(['id' => $demand, 'physical_request_id' => $physical,
                'operation_id' => $context->lease()->id->value, 'operation_generation' => 1, 'origin_budget_id' => $origin,
                'route_id' => $this->route->routeId->value, 'target_id' => $this->target->value,
                'traffic_class' => 'status_refresh', 'purpose' => 'ordinary', 'state' => 'granted', 'registered_at' => $when,
                'renewed_at' => $when, 'expires_at' => $when, 'operation_deadline' => $context->lease()->deadlineAt]);
            DB::table('prc_network_permits')->insert(['id' => LedgerFixtures::uuid()->value, 'demand_id' => $demand,
                'slot_at' => $when, 'expires_at' => DB::raw("clock_timestamp() + interval '34 seconds'"), 'state' => 'released',
                'authorized_at' => $when, 'policy_versions' => '{}', 'policy_schema_version' => 1,
                'health_epochs' => '{}', 'health_epochs_schema_version' => 1, 'created_at' => $when]);
            DB::table('prc_request_attempts')->insert(['request_id' => $physical, 'demand_id' => $demand, 'request_seq' => $i + 1,
                'target_id' => $this->target->value, 'route_id' => $this->route->routeId->value, 'started_at' => $when, 'sent_at' => $when,
                'finished_at' => $when, 'phase' => 'finished', 'outcome' => 'response', 'epochs' => '{}', 'epochs_schema_version' => 1]);
        }
    }
}
