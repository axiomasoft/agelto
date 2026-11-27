<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma\Caller;

use App\Dto\Procurement\Network\Control\OutboundExecutionContext;
use App\Dto\Procurement\Network\Control\OutboundOperationHandle;
use App\Dto\Procurement\Network\Control\OutboundRequestPlan;
use App\Dto\Procurement\Network\Control\ProviderKey;
use App\Dto\Procurement\Network\Http\SafeUrl;
use App\Dto\Procurement\Network\Http\TransferBudget;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Execution\ExecutionLease;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\Duration;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Network as E;
use App\Exceptions\Procurement\Network\OutboundOwnershipLost;
use App\Exceptions\Procurement\Process\ExecutionInterrupted;
use App\Models\Procurement\Proxyma\TargetLink;
use App\Services\Procurement\Network\UrlPolicy;
use App\Services\Procurement\Proxyma\Catalog\BindingAdapter;
use App\Services\Procurement\Proxyma\Catalog\ProcessOwnerAuthority;
use App\Services\Procurement\Proxyma\Catalog\TargetCatalogAdapter;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Proxyma\Application\Admission\DemandQueue;
use Proxyma\Application\Admission\OperationCommands;
use Proxyma\Application\Admission\ReservationService;
use Proxyma\Application\Execution\OutboundExecutor;
use Proxyma\Application\Health\ProbeCoordinator;
use Proxyma\Application\Policy\PolicyResolver;
use Proxyma\Application\Routing\RouteSelector;
use Proxyma\Contracts\FiniteExecutionPort;
use Proxyma\Contracts\PhysicalTransport;
use Proxyma\Contracts\WorkBudget;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Catalog\Route;
use Proxyma\Domain\Execution as X;
use Proxyma\Domain\Policy\EffectivePolicy;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Domain\Routing\CandidateSnapshot;
use Proxyma\Infrastructure\Database\DatabaseClock;
use Proxyma\Infrastructure\Database\PackageSchema;

/** Process owner и target mapping для одного immutable physical operation. */
final readonly class CallerExecutionPort implements FiniteExecutionPort
{
    public function __construct(private OutboundRequestPlan $original, private ExecutionContext|OutboundExecutionContext $context,
        private CallerGate $gate, private UrlPolicy $origins, private TransferBudget $budget) {}

    public function now(): \DateTimeImmutable
    {
        return DatabaseClock::now(app(ProcessOwnerAuthority::class)->connection());
    }

    public function canStart(X\Duration $required): bool
    {
        $this->context->tick();

        return $this->context->canStart(new Duration($required->milliseconds));
    }

    public function redirectTarget(X\Uuid $from, X\SafeUrl $destination): ?X\Uuid
    {
        if (! $this->origins->allows(new SafeUrl($destination->reveal()))) {
            return null;
        }
        $source = app(TargetCatalogAdapter::class)->sourceFor($from);
        if ($source === null) {
            return null;
        }
        $mapped = DB::table((new TargetLink)->getTable().' as l')->join('prc_source_targets as s', 's.id', '=', 'l.source_target_id')
            ->where('l.tenant_key', TenantKey::SYSTEM)->where('s.platform_id', $source->platform_id)->where('s.provider_key', $source->provider_key)
            ->where('s.capability', $source->capability)->where('s.canonical_origin', $destination->origin->value())->whereNull('s.retired_at')
            ->orderBy('s.id')->value('l.target_id');
        if (! is_string($mapped)) {
            return null;
        }
        $id = new X\Uuid($mapped);
        app(TargetCatalogAdapter::class)->redirect($from, $id, $destination->origin);

        return $id;
    }

    public function executeAttempt(X\OutboundRequest $request, X\RouteSelectionContext $routes, int $attempt, int $redirects): X\PhysicalExecutionResult|X\ExecutionResult
    {
        $context = $this->context;
        $source = app(TargetCatalogAdapter::class)->sourceFor($request->targetId) ?? throw new AdmissionRejected('target_missing');
        $plan = new OutboundRequestPlan(new ProcessUuid($request->operationId->value), new ProcessUuid($source->id),
            $request->method, new SafeUrl($request->url->reveal()), $this->original->profile, $this->original->trafficClass,
            $this->original->idempotency, $request->body === null ? null : new ContentHash($request->body->hash->hex),
            $this->original->sendLimit, $this->original->adapterClaim);
        if (! $this->origins->allows($plan->url)) {
            return new X\ExecutionBlocked(X\BlockedReason::UrlPolicyDenied);
        }
        $gate = $this->gate;
        $context->tick();
        $tenant = TenantKey::system();
        $targetId = $request->targetId;
        $lease = $context->lease();
        $probe = $lease instanceof OutboundOperationHandle && $lease->kind === E\OutboundOperationKind::Probe;
        $notification = $source->provider_key === 'telegram' && $source->capability === 'notification'
            && $lease instanceof OutboundOperationHandle && DB::table('prc_outbound_operations')
                ->where('id', $lease->id->value)->where('caller_code', 'notification.telegram')->exists();
        if ($source->provider_key !== null && ! $notification
            && ! ($probe && in_array($plan->method, ['GET', 'HEAD'], true)
                && DB::table('prc_outbound_operations')->where('id', $lease->id->value)->where('caller_code', 'network.probe')->exists())) {
            throw new AdmissionRejected('provider_owner_required');
        }
        $settings = CallerPolicyContext::resolve($plan, $context);
        $effective = app(PolicyResolver::class)->resolveEffective($settings->subjects, $targetId, $settings->slot, panelKey: $settings->panel, bypassCache: true);
        $callerTimeouts = $request->timeouts;
        $maxAttempts = min($callerTimeouts->maxAttempts, $effective->timeouts->maxAttempts);
        $delays = array_slice($callerTimeouts->retryDelaysMs, 0, $maxAttempts - 1);
        if ($maxAttempts <= 1) {
            $delays = [];
        } elseif (($effective->explanations['retry.delays_ms']->explicit ?? false) && $effective->timeouts->retryDelaysMs !== []) {
            $delays = [];
            foreach (array_slice($effective->timeouts->retryDelaysMs, 0, $maxAttempts - 1) as $i => $delay) {
                $delays[] = min($callerTimeouts->retryDelaysMs[$i] ?? PHP_INT_MAX, $delay);
            }
        }
        $total = min($callerTimeouts->totalTimeoutMs, $effective->timeouts->totalTimeoutMs);
        $timeouts = new X\RequestTimeoutPolicy(
            min($total, $callerTimeouts->connectTimeoutMs, $effective->timeouts->connectTimeoutMs),
            min($total, $callerTimeouts->readTimeoutMs, $effective->timeouts->readTimeoutMs), $total,
            max($callerTimeouts->cleanupMarginMs, $effective->timeouts->cleanupMarginMs), $maxAttempts,
            min($maxAttempts, $callerTimeouts->maxDistinctRoutes, $effective->timeouts->maxDistinctRoutes),
            min($callerTimeouts->maxRedirects, $effective->timeouts->maxRedirects), retryDelaysMs: $delays);
        $request = new X\OutboundRequest($request->operationId, $request->targetId, $request->method, $request->url,
            $timeouts, $request->idempotency, $request->headers, $request->body, $settings->slot,
            responseProfile: $request->responseProfile, outcomePolicyKey: $request->outcomePolicyKey);
        $bindings = $gate->routePolicy();
        $profile = $effective->routeProfileId ?? ($bindings->profileId === null ? null : app(BindingAdapter::class)->activeProfile($bindings->profileId)->id);
        if ($profile === null) {
            throw new AdmissionRejected('profile_missing');
        }
        $purpose = $lease instanceof ExecutionLease || $lease->kind === E\OutboundOperationKind::LegacyBridge
            ? X\DemandPurpose::Ordinary : X\DemandPurpose::Diagnostic;
        $probeRoute = null;
        if ($probe) {
            $preflight = new X\WireRequest($request->operationId, $request->operationId, $request->targetId, $effective->effectiveRevision,
                $request->method, $request->url, $request->timeouts, min($lease->leaseUntil, $lease->deadlineAt),
                $request->headers, $request->body, $request->idempotency, $purpose, trafficClass: $request->trafficKey,
                responseProfile: $request->responseProfile);
            $normal = app(RouteSelector::class)->select($tenant, $profile, $effective, $preflight, $routes);
            $probeRoute = $this->probeRoute(snapshot: $normal->snapshot, target: $targetId, policy: $effective,
                allowBootstrap: $normal->route === null);
            if ($probeRoute !== null) {
                $purpose = X\DemandPurpose::Probe;
            } elseif ($normal->route === null) {
                throw new AdmissionRejected('probe_route_missing');
            }
        }
        $networkProbe = $purpose === X\DemandPurpose::Probe;
        $owner = app(ProcessOwnerAuthority::class)->reference($lease,
            $source->provider_key === null ? null : new ProviderKey($source->provider_key),
            networkPurpose: $probe ? $purpose : null, probe: $networkProbe ? new X\Uuid($plan->physicalRequestId->value) : null);
        $deadline = $lease instanceof ExecutionLease ? $lease->leaseUntil : min($lease->leaseUntil, $lease->deadlineAt);
        $operation = new X\OperationCorrelation(new X\Uuid($plan->physicalRequestId->value), $owner, $deadline,
            $plan->physicalRequestId->value, $purpose, $networkProbe ? new X\Uuid($plan->physicalRequestId->value) : null);
        $timeouts = $request->timeouts;
        $packageContext = new X\ExecutionContext($operation, $settings->subjects, new X\Duration($context->remainingSlice()->milliseconds),
            hrtime(true) + $context->remainingSlice()->nanos());
        $parent = $lease instanceof ExecutionLease ? $lease->attemptId->value
            : DB::table('prc_outbound_operations')->where('id', $lease->id->value)->value('parent_attempt_id');
        app(OperationCommands::class)->create($operation, $profile, $targetId, $timeouts, is_string($parent) ? new X\Uuid($parent) : null);
        app(AdapterSendGuard::class)->prepare($plan);
        if ($probeRoute !== null) {
            $this->prepareProbe(plan: $plan, context: $context, request: $request, operation: $operation,
                profile: $profile, effective: $effective, route: $probeRoute);
        }
        $transport = app()->makeWith(PhysicalTransport::class, ['maxResponseBytes' => $plan->profile->maxEntityBytes,
            'cancelled' => static function () use ($context): bool {
                try {
                    $context->tick();

                    return false;
                } catch (OutboundOwnershipLost|ExecutionInterrupted) {
                    return true;
                }
            }]);
        $result = app()->makeWith(OutboundExecutor::class, ['policies' => new CallerPolicyResolver(app(PolicyResolver::class), $settings->panel),
            'transport' => $transport])->executeAttempt($request, $packageContext, $routes, $attempt, $redirects);
        if ($result instanceof X\ExecutionDeferred) {
            $this->cancelDeferredDemand($tenant, $operation, new X\Uuid($plan->physicalRequestId->value));
        }
        if ($result instanceof X\PhysicalExecutionResult) {
            $this->budget->wire($result->result->wireBytes);
            $this->budget->entity($result->result->decodedBytes);
        }

        return $result;
    }

    private function cancelDeferredDemand(TenantKey $tenant, X\OperationCorrelation $operation, X\Uuid $physicalAttempt): void
    {
        $tables = PackageSchema::tables();
        $demand = DB::table($tables['demands'].' as demand')
            ->where('demand.tenant_key', $tenant->value)
            ->where('demand.operation_id', $operation->operationId->value)
            ->where('demand.physical_attempt_id', $physicalAttempt->value)
            ->whereIn('demand.state', ['active', 'admitted'])
            ->whereNotExists(function (Builder $query) use ($tables): void {
                $query->selectRaw('1')->from($tables['permits'].' as permit')
                    ->whereColumn('permit.demand_id', 'demand.id');
            })
            ->value('demand.id');
        if (! is_string($demand)) {
            return;
        }

        app(DemandQueue::class)->cancel($tenant, $operation->operationId, new X\Uuid($demand));
    }

    private function probeRoute(CandidateSnapshot $snapshot, X\Uuid $target, EffectivePolicy $policy, bool $allowBootstrap): ?Route
    {
        $profile = $snapshot->profile;
        if ($profile === null) {
            return null;
        }
        $db = app(ProcessOwnerAuthority::class)->connection();
        $tables = PackageSchema::tables();
        $targetState = $this->probeHealth($db->table($tables['target_health'])
            ->where('tenant_key', TenantKey::SYSTEM)->where('target_id', $target->value));
        if ($targetState === 'blocked') {
            return null;
        }

        $targetRecovery = null;
        $bootstrap = null;
        foreach ($snapshot->candidates as $candidate) {
            $route = $candidate->route;
            if ($route === null || ! $route->enabled || $route->isRetired() || ! $policy->allowsRoute(route: $route, profile: $profile)) {
                continue;
            }
            $routeState = $this->probeHealth($db->table($tables['route_health'])->where('tenant_key', $route->tenantKey->value)
                ->where('route_id', $route->id->value)->whereNull('credential_endpoint_id'));
            $pairState = $this->probeHealth($db->table($tables['route_target_health'])->where('tenant_key', $route->tenantKey->value)
                ->where('route_id', $route->id->value)->where('target_id', $target->value));
            $states = [$targetState, $routeState, $pairState];
            if (in_array('blocked', $states, true)) {
                continue;
            }
            if ($routeState === 'due' || $pairState === 'due') {
                return $route;
            }
            if ($targetState === 'due') {
                $targetRecovery ??= $route;
            }
            if ($allowBootstrap && in_array('unknown', $states, true)) {
                $bootstrap ??= $route;
            }
        }

        return $targetRecovery ?? $bootstrap;
    }

    /** Unlocked candidate read. ProbeCoordinator rechecks the selected route and health under locks. */
    private function probeHealth(Builder $health): string
    {
        $state = $health->whereRaw("NOT starts_with(COALESCE(reason_code, ''), 'credential_hold')")
            ->selectRaw("CASE
                WHEN (state = 'open' AND open_until <= clock_timestamp())
                    OR (state = 'half_open' AND probe_until <= clock_timestamp()) THEN 'due'
                WHEN state IN ('closed', 'unknown') THEN state
                ELSE 'blocked' END AS probe_state")
            ->first()?->probe_state;

        return is_string($state) ? $state : 'blocked';
    }

    private function prepareProbe(OutboundRequestPlan $plan, ExecutionContext|OutboundExecutionContext $context,
        X\OutboundRequest $request, X\OperationCorrelation $operation, X\Uuid $profile, EffectivePolicy $effective, Route $route): void
    {
        $wire = new X\WireRequest($request->operationId, new X\Uuid($plan->physicalRequestId->value), $request->targetId,
            $effective->effectiveRevision, $request->method, $request->url, $request->timeouts, $operation->deadlineAt,
            $request->headers, $request->body, $request->idempotency, X\DemandPurpose::Probe, trafficClass: $request->trafficKey,
            responseProfile: $request->responseProfile);
        $selection = new X\RouteSelection($route->id, $route->kind, $profile, $route->endpointId, $route->credentialVersion, $route->egress, $route->independenceGroup);
        $work = new class($context) implements WorkBudget
        {
            public function __construct(private ExecutionContext|OutboundExecutionContext $context) {}

            public function canStart(X\Duration $required): bool
            {
                $this->context->tick();

                return $this->context->canStart(new Duration($required->milliseconds));
            }
        };
        // Consumer exception: начальный unknown становится due probe; PostgreSQL остаётся authority времени.
        $tables = PackageSchema::tables();
        foreach (['target_health' => ['tenant_key' => TenantKey::SYSTEM, 'target_id' => $request->targetId->value],
            'profile_health' => ['tenant_key' => TenantKey::SYSTEM, 'profile_id' => $profile->value],
            'route_health' => ['tenant_key' => $route->tenantKey->value, 'route_id' => $route->id->value],
            'route_target_health' => ['tenant_key' => $route->tenantKey->value, 'route_id' => $route->id->value, 'target_id' => $request->targetId->value]] as $name => $identity) {
            $patch = ['state' => 'open', 'open_until' => DB::raw("clock_timestamp() - interval '1 second'")];
            if ($name === 'target_health') {
                $patch['availability'] = 'unavailable';
            }
            DB::table($tables[$name])->where($identity)->where('state', 'unknown')->update($patch);
        }
        $input = app(ReservationService::class)->prepare($wire, $operation, $selection, $effective, $work);
        app(ProbeCoordinator::class)->claim($input, min(300, $plan->profile->totalSeconds + 30));
    }
}
