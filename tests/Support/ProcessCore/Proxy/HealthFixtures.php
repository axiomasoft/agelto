<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore\Proxy;

use App\Dto\Procurement\Network\DecodedTextBody;
use App\Dto\Procurement\Network\HttpAttemptResult;
use App\Dto\Procurement\Network\HttpRouteBinding;
use App\Dto\Procurement\Network\NetworkDemandTicket;
use App\Dto\Procurement\Network\NetworkPermitWait;
use App\Dto\Procurement\Network\NetworkRouteSelection;
use App\Dto\Procurement\Network\NetworkSendClaim;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\ProbeClaim;
use App\Dto\Procurement\Network\ResponseEnvelope;
use App\Dto\Procurement\Network\ScheduledNetworkPermit;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\TransportFailure;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Network\ScheduledAttemptGate;
use App\Services\Procurement\Proxy\ProxyResolver;
use App\Services\Procurement\Proxy\RoutePolicy;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;

final class HealthFixtures
{
    public readonly PermitFixtures $permits;

    public readonly ProcessUuid $profile;

    public function __construct(public readonly WireFixture $wire)
    {
        $this->permits = new PermitFixtures($wire);
        $this->profile = LedgerFixtures::uuid();
        DB::table('prc_network_route_profiles')->insert(['id' => $this->profile->value, 'profile_key' => 'health.'.bin2hex(random_bytes(6)), 'policy_version' => 1]);
        $this->member($this->permits->route->routeId);
        DB::table('prc_network_budgets')->update(['emission_interval_us' => 1]);
    }

    public function proxy(?int $port = null, ?string $group = null): HttpRouteBinding
    {
        $id = LedgerFixtures::uuid();
        DB::table('prc_network_routes')->insert(['id' => $id->value, 'route_key' => 'fixture.'.$id->value, 'kind' => 'proxy',
            'egress_id' => 'fixture.'.$id->value, 'independence_group' => $group, 'enabled' => true, 'credential_version' => 1]);
        DB::table('prc_route_health')->insert(['id' => LedgerFixtures::uuid()->value, 'route_id' => $id->value, 'state' => 'closed',
            'health_epoch' => 1, 'state_version' => 1, 'recent_openings' => 0, 'consecutive_failures' => 0]);
        DB::table('prc_route_target_health')->insert(['id' => LedgerFixtures::uuid()->value, 'route_id' => $id->value,
            'target_id' => $this->permits->target->value, 'state' => 'closed', 'health_epoch' => 1, 'state_version' => 1, 'recent_openings' => 0, 'consecutive_failures' => 0]);
        DB::table('prc_network_budgets')->insert(['id' => LedgerFixtures::uuid()->value,
            'scope_key' => $this->permits->url->origin->routeScopeKey($id->value), 'emission_interval_us' => 1, 'burst' => 1, 'max_inflight' => 1, 'policy_version' => 1]);
        $this->member($id);

        return new HttpRouteBinding($id, 'http://127.0.0.1:'.($port ?? $this->wire->ports['proxy']));
    }

    private function member(ProcessUuid $id): void
    {
        DB::table('prc_network_route_profile_members')->insert(['profile_id' => $this->profile->value, 'route_id' => $id->value]);
    }

    /** @param list<HttpRouteBinding> $bindings */
    public function gate(array $bindings, ?ProbeClaim $claim = null): ScheduledAttemptGate
    {
        return new ScheduledAttemptGate($this->permits->repo,
            app(ProxyResolver::class)->outboundRoutes(new RoutePolicy($bindings, $this->profile), $claim), app(OutboundPolicy::class));
    }

    public function authorize(OutboundExecutionContext $context, ?HttpRouteBinding $binding = null): ScheduledNetworkPermit
    {
        $route = $binding === null ? $this->permits->route : new NetworkRouteSelection($binding->id, $this->profile);
        $ticket = $this->permits->repo->register($this->permits->plan(), $context, $route);
        if (! $ticket instanceof NetworkDemandTicket) {
            throw new \LogicException('Fixture registration denied: '.get_debug_type($ticket));
        }
        $permit = $this->permits->repo->reserve($ticket, $context);
        if (! $permit instanceof ScheduledNetworkPermit) {
            throw new \LogicException('Fixture reservation denied: '.get_debug_type($permit));
        }
        do {
            $claim = $this->permits->repo->claimSend($permit, $context);
            if ($claim instanceof NetworkPermitWait) {
                usleep(1000);
            }
        } while ($claim instanceof NetworkPermitWait);
        if (! $claim instanceof NetworkSendClaim || ! $claim->claimed) {
            throw new \LogicException('Fixture send denied: '.get_debug_type($claim));
        }

        return $permit;
    }

    /** Deterministic reducer input; real SQL grant/intent, no mocked network/concurrency assertion. */
    public function outcome(HttpAttemptResult $result, ?HttpRouteBinding $binding = null): void
    {
        $permit = $this->authorize($this->permits->context(), $binding);
        $this->permits->repo->record($permit->ticket->plan->physicalRequestId, $result);
    }

    public static function failure(TransportFailure $failure = TransportFailure::Timeout): HttpAttemptResult
    {
        return new HttpAttemptResult(null, $failure, 0, 0);
    }

    public static function success(): HttpAttemptResult
    {
        return new HttpAttemptResult(new ResponseEnvelope(200, [], new DecodedTextBody('ok', 'UTF-8'), 2, 2), null, 2, 2);
    }
}
