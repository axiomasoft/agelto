<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore\Http;

use App\Dto\Procurement\Network\AuthorizedHttpRequest;
use App\Dto\Procurement\Network\HttpRequestPayload;
use App\Dto\Procurement\Network\HttpRouteBinding;
use App\Dto\Procurement\Network\NetworkDemandTicket;
use App\Dto\Procurement\Network\NetworkRouteSelection;
use App\Dto\Procurement\Network\NetworkSendClaim;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\OutboundOperationHandle;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\RetryRoutePreference;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Network\ScheduledNetworkPermit;
use App\Dto\Procurement\Network\SelectedNetworkRoute;
use App\Dto\Procurement\Network\TransferBudget;
use App\Dto\Procurement\Platform\OriginBudgetPolicy;
use App\Dto\Procurement\Platform\RequestProfile;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Platform\SourceTargetDefinitions;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\OutboundOperationKind;
use App\Enums\Procurement\Network\TrafficClass;
use App\Repositories\Procurement\Network\NetworkPermitRepository;
use App\Repositories\Procurement\Network\OutboundOperationRepository;
use App\Services\Procurement\Network\Contracts\OutboundRouteSelector;
use App\Services\Procurement\Network\HttpTransport;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Network\ScheduledAttemptGate;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\NetworkFixtures;
use Tests\Support\TestEnvironmentGuard;

/** Real owners, catalog and PostgreSQL. Only health readiness/time facts are fixture setup. */
final class PermitFixtures
{
    public readonly string $code;

    public readonly ProcessUuid $target;

    public readonly NetworkRouteSelection $route;

    public readonly RequestProfile $profile;

    public readonly SafeUrl $url;

    public readonly NetworkPermitRepository $repo;

    public function __construct(?WireFixture $wire = null)
    {
        $this->code = 'permit_'.bin2hex(random_bytes(6));
        NetworkFixtures::platform($this->code);
        $this->url = $wire?->url('/ok') ?? new SafeUrl('https://example.test/ok');
        $this->profile = TransportFixtures::profile(['total_seconds' => 4]);
        app(SourceCatalogSync::class)->sync(new SourceTargetDefinitions($this->code, [
            new SourceTargetDefinition('card', 'detail', $this->url->origin, [$this->profile], new OriginBudgetPolicy(600, 1, 2)),
        ]));
        $this->target = new ProcessUuid(DB::table('prc_source_targets')->where('canonical_origin', $this->url->origin->value())->sole()->id);
        $this->route = new NetworkRouteSelection(new ProcessUuid(DB::table('prc_network_routes')->sole()->id));
        foreach (['prc_route_health', 'prc_route_target_health', 'prc_target_health'] as $table) {
            DB::table($table)->update(['state' => 'closed']);
        }
        // Test scaling keeps the same GCRA inequalities; production defaults remain 15/min.
        DB::table('prc_network_budgets')->update(['emission_interval_us' => 100_000]);
        $this->repo = app(NetworkPermitRepository::class);
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
        DB::statement('TRUNCATE prc_network_routes, prc_source_targets, prc_outbound_operations CASCADE');
        DB::table('prc_control_scopes')->where('scope_key', 'system')->update(['desired_mode' => 'enabled']);
    }

    public function context(?\Closure $checkpoint = null, OutboundOperationKind $kind = OutboundOperationKind::LegacyBridge, ?ProcessUuid $probeToken = null): OutboundExecutionContext
    {
        $owner = app(ProcessTransaction::class)->run(fn () => app(OutboundOperationRepository::class)->open(
            LedgerFixtures::uuid(), LedgerFixtures::uuid(), $kind, null, 'fixture.permits', LedgerFixtures::hash('policy'),
            ['acquisition:platform:'.$this->code], 120, 120, $probeToken,
        ));

        return new OutboundExecutionContext($owner, fn ($handle) => app(ProcessTransaction::class)->run(fn () => app(OutboundOperationRepository::class)->renew($handle, 120)), $checkpoint, 120, 15);
    }

    public function plan(TrafficClass $class = TrafficClass::Detail, ?SafeUrl $url = null, ?ProcessUuid $physicalId = null): OutboundRequestPlan
    {
        return new OutboundRequestPlan($physicalId ?? LedgerFixtures::uuid(), $this->target, 'GET', $url ?? $this->url, $this->profile, $class, IdempotencyClass::ReadOnly);
    }

    public function ticket(OutboundExecutionContext|ExecutionContext $context, ?OutboundRequestPlan $plan = null): NetworkDemandTicket
    {
        $ticket = $this->repo->register($plan ?? $this->plan(), $context, $this->route);

        return $ticket instanceof NetworkDemandTicket ? $ticket : throw new \LogicException('Fixture ticket rejected: '.get_debug_type($ticket));
    }

    public function reserve(OutboundExecutionContext|ExecutionContext $context, ?OutboundRequestPlan $plan = null): ScheduledNetworkPermit
    {
        $result = $this->repo->reserve($this->ticket($context, $plan), $context);

        return $result instanceof ScheduledNetworkPermit ? $result : throw new \LogicException('Fixture reservation rejected: '.get_debug_type($result));
    }

    public function gate(): ScheduledAttemptGate
    {
        return new ScheduledAttemptGate($this->repo, new class($this->route) implements OutboundRouteSelector
        {
            public function __construct(private NetworkRouteSelection $route) {}

            public function select(OutboundRequestPlan $plan, RetryRoutePreference $preference): SelectedNetworkRoute
            {
                return new SelectedNetworkRoute($this->route, new HttpRouteBinding($this->route->routeId));
            }
        }, app(OutboundPolicy::class));
    }

    public function send(ScheduledNetworkPermit $permit, OutboundExecutionContext|ExecutionContext $context, WireFixture $wire): bool
    {
        $claim = $this->repo->claimSend($permit, $context);
        if (! $claim instanceof NetworkSendClaim || ! $claim->claimed) {
            return false;
        }
        $request = new AuthorizedHttpRequest($permit->ticket->plan, new HttpRouteBinding($this->route->routeId));
        $resolved = $wire->policy()->resolve($request->plan->url, $context, 1);
        $result = app(HttpTransport::class)->sendOnce($request, $resolved, new HttpRequestPayload, $context, new TransferBudget(1_000_000, 1_000_000));
        $this->repo->record($request->plan->physicalRequestId, $result);

        return true;
    }

    /** Prior durable authorizations, not a fake runtime grant. Current sends still use the gate. */
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

    public static function importTicket(string $id, string $url): NetworkDemandTicket
    {
        $row = DB::table('prc_network_demands')->where('id', $id)->sole();
        $plan = new OutboundRequestPlan(new ProcessUuid($row->physical_request_id), new ProcessUuid($row->target_id), 'GET',
            new SafeUrl($url), TransportFixtures::profile(), TrafficClass::from($row->traffic_class), IdempotencyClass::ReadOnly);

        return new NetworkDemandTicket(new ProcessUuid($id), (int) $row->ticket_seq, $plan, new NetworkRouteSelection(new ProcessUuid($row->route_id)));
    }

    public static function importContext(string $operationId): OutboundExecutionContext
    {
        $row = DB::table('prc_outbound_operations')->where('id', $operationId)->sole();
        $handle = new OutboundOperationHandle(new ProcessUuid($row->id), OutboundOperationKind::from($row->kind), (int) $row->generation,
            new ProcessUuid($row->owner_token), new \DateTimeImmutable($row->lease_until), new \DateTimeImmutable($row->deadline_at));

        return new OutboundExecutionContext($handle, fn ($owner) => app(ProcessTransaction::class)->run(fn () => app(OutboundOperationRepository::class)->renew($owner, 120)), null, 120, 15);
    }
}
