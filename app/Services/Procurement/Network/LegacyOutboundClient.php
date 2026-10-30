<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Network\AdapterInspection;
use App\Dto\Procurement\Network\AdapterValidatedInput;
use App\Dto\Procurement\Network\AdapterValidationCall;
use App\Dto\Procurement\Network\BinaryBodyArtifact;
use App\Dto\Procurement\Network\DecodedTextBody;
use App\Dto\Procurement\Network\HttpRequestPayload;
use App\Dto\Procurement\Network\HttpRouteBinding;
use App\Dto\Procurement\Network\NetworkRouteSelection;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\ProbeClaim;
use App\Dto\Procurement\Network\ProviderKey;
use App\Dto\Procurement\Network\RetryRoutePreference;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Network\TransferBudget;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Network\TransportOutcome;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Dto\Procurement\Platform\RequestProfile;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Network\OutboundOperationKind;
use App\Enums\Procurement\Network\TrafficClass;
use App\Exceptions\Procurement\Network\OutboundOwnershipLost;
use App\Exceptions\Procurement\Network\OutboundUnavailable;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Repositories\Procurement\Network\AdapterHealthRepository;
use App\Repositories\Procurement\Network\NetworkPermitRepository;
use App\Repositories\Procurement\Network\SourceCatalogRepository;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Network\Contracts\LegacyOutbound;
use App\Services\Procurement\Process\DatabaseFailureClassifier;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Proxy\ProxyResolver;
use App\Services\Procurement\Proxy\RoutePolicy;
use Closure;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Str;

/** Temporary network seam only. Existing callers retain their business scheduler and persistence. */
final readonly class LegacyOutboundClient implements LegacyOutbound
{
    public function __construct(
        private LegacyBridge $bridge,
        private OutboundExecutor $executor,
        private NetworkPermitRepository $permits,
        private ProxyResolver $routes,
        private OutboundPolicy $policy,
        private SourceCatalogRepository $catalog,
        private ProbeScheduler $probes,
        private AdapterHealthRepository $adapters,
        private ProcessTransaction $transaction,
        private ?UrlPolicy $urls = null,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(OutboundExecutionContext): AdapterInspection<T>  $inspect
     * @return AdapterValidatedInput<T>
     */
    public function validated(AdapterValidationCall $call, Closure $inspect, ?Closure $checkpoint = null): AdapterValidatedInput
    {
        try {
            $health = $this->adapters->find($call->contract);
            $target = $this->catalog->targetForCall($call->platformId, null, $call->contract->capability, $call->firstUrl->origin->value());
            if ($health === null || $target === null) {
                throw new OutboundUnavailable(new TransportBlocked(NetworkBlockedReason::ConfigurationHold));
            }
            if ($health->string('state') === 'held') {
                throw new OutboundUnavailable(new TransportBlocked(NetworkBlockedReason::AdapterHeld));
            }
            $definition = json_decode($target->string('endpoint_policy'), true, flags: JSON_THROW_ON_ERROR);
            $profiles = is_array($definition) ? ($definition['profiles'] ?? null) : null;
            $encoded = is_array($profiles) ? ($profiles[0] ?? null) : null;
            if (! is_array($encoded)) {
                throw new OutboundUnavailable(new TransportBlocked(NetworkBlockedReason::ConfigurationHold));
            }
            $profile = RequestProfile::fromArray($encoded);
            $plan = new OutboundRequestPlan(self::uuid(), new ProcessUuid($target->string('id')), 'GET', $call->firstUrl,
                $profile, $call->trafficClass, IdempotencyClass::ReadOnly);
            $selection = $this->routes->outboundRoutes($call->routes)->select($plan,
                new RetryRoutePreference([], null, false, $profile->distinctRoutes));
            if ($selection instanceof TransportBlocked) {
                throw new OutboundUnavailable($selection);
            }
            $kind = $health->string('state') !== 'healthy' || $selection instanceof TransportDeferred
                ? OutboundOperationKind::Probe : ($call->trafficClass === TrafficClass::Diagnostic ? OutboundOperationKind::Diagnostic : OutboundOperationKind::LegacyBridge);

            return $this->bridge->run($call->callerCode, $call->platformId, $call->controls, $call->deadlineSeconds,
                $call->contract->fingerprint(), function (OutboundExecutionContext $context) use ($call, $inspect) {
                    $claim = $this->transaction->run(fn () => $this->adapters->admit($call->contract, $context));
                    if ($claim instanceof TransportBlocked || $claim instanceof TransportDeferred) {
                        throw new OutboundUnavailable($claim);
                    }
                    $context->bindAdapter($claim);
                    try {
                        $inspection = $inspect($context);
                        $receipt = $this->transaction->run(fn () => $this->adapters->observe($context, $call->inputHash, $inspection));

                        if ($receipt->stale) {
                            throw new OutboundOwnershipLost('Full validation authority changed before observation.');
                        }

                        return new AdapterValidatedInput($inspection->value, $receipt);
                    } finally {
                        try {
                            $this->transaction->run(fn () => $this->adapters->release($context));
                        } catch (\Throwable $failure) {
                            report($failure);
                        }
                    }
                }, $checkpoint, kind: $kind);
        } catch (\Throwable $failure) {
            if (DatabaseFailureClassifier::isTransient($failure) || $failure instanceof DependencyUnavailable) {
                throw OutboundUnavailable::dependency();
            }
            throw $failure;
        }
    }

    private static function uuid(): ProcessUuid
    {
        return new ProcessUuid(Str::lower((string) Str::uuid()));
    }

    /** @param list<string> $controls */
    public function execute(
        string $callerCode,
        ?int $platformId,
        ?ProviderKey $providerKey,
        string $targetKey,
        string $profileCode,
        SafeUrl $url,
        TrafficClass $trafficClass,
        RoutePolicy $routePolicy,
        array $controls,
        int $deadlineSeconds,
        string $method = 'GET',
        HttpRequestPayload $payload = new HttpRequestPayload,
        IdempotencyClass $idempotency = IdempotencyClass::ReadOnly,
        ?Closure $checkpoint = null,
        ?UrlPolicy $urlPolicy = null,
        ?int $sendLimit = null,
        bool $probeOnly = false,
        ?OutboundExecutionContext $context = null,
    ): TransportOutcome {
        if ($probeOnly && ($idempotency !== IdempotencyClass::ReadOnly || ! in_array($method, ['GET', 'HEAD'], true))) {
            return new TransportBlocked(NetworkBlockedReason::ConfigurationHold);
        }
        $target = LedgerRow::one('SELECT id, endpoint_policy::text AS endpoint_policy FROM prc_source_targets
            WHERE platform_id IS NOT DISTINCT FROM ?::bigint AND provider_key IS NOT DISTINCT FROM ?::text
              AND target_key = ? AND canonical_origin = ? AND retired_at IS NULL',
            [$platformId, $providerKey?->value, $targetKey, $url->origin->value()]);
        if ($target === null) {
            return new TransportBlocked(NetworkBlockedReason::ConfigurationHold);
        }
        try {
            $definition = json_decode($target->string('endpoint_policy'), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new TransportBlocked(NetworkBlockedReason::ConfigurationHold);
        }
        if (! is_array($definition) || ! isset($definition['profiles']) || ! is_array($definition['profiles'])) {
            return new TransportBlocked(NetworkBlockedReason::ConfigurationHold);
        }
        $profiles = [];
        foreach ($definition['profiles'] as $encoded) {
            if (! is_array($encoded)) {
                return new TransportBlocked(NetworkBlockedReason::ConfigurationHold);
            }
            try {
                $profile = RequestProfile::fromArray($encoded);
            } catch (\InvalidArgumentException) {
                return new TransportBlocked(NetworkBlockedReason::ConfigurationHold);
            }
            $profiles[$profile->code] = $profile;
        }
        $profile = $profiles[$profileCode] ?? null;
        if ($profile === null) {
            return new TransportBlocked(NetworkBlockedReason::ConfigurationHold);
        }
        $plan = new OutboundRequestPlan(new ProcessUuid(Str::lower((string) Str::uuid())), new ProcessUuid($target->string('id')),
            $method, $url, $profile, $trafficClass, $idempotency,
            $payload->body === null ? null : new ContentHash(hash('sha256', $payload->body)), $sendLimit, $context?->adapterClaim());
        $hash = new ContentHash(hash('sha256', json_encode($profile->toArray(), JSON_THROW_ON_ERROR)));
        $policy = $urlPolicy ?? $this->urls ?? new UrlPolicy([$url->origin]);
        $selection = $this->routes->outboundRoutes($routePolicy)->select($plan,
            new RetryRoutePreference([], null, false, $profile->distinctRoutes));
        if ($context !== null) {
            $context->tick();
            if ($context->lease()->kind === OutboundOperationKind::Probe) {
                $canary = new OutboundRequestPlan($plan->physicalRequestId, $plan->targetId, $plan->method,
                    $plan->url, $plan->profile, $plan->trafficClass, $plan->idempotency, $plan->bodyHash,
                    min($plan->sendLimit ?? PHP_INT_MAX, $plan->profile->redirects + 1), $plan->adapterClaim);

                return $this->executor->execute($canary, $context,
                    new ScheduledAttemptGate($this->permits,
                        new AdapterCanaryRouteSelector($context, $routePolicy, $this->probes, $this->routes), $this->policy),
                    $policy, new TransferBudget($profile->maxEntityBytes, $profile->maxEntityBytes), $payload);
            }

            return $this->executor->execute($plan, $context,
                new ScheduledAttemptGate($this->permits, $this->routes->outboundRoutes($routePolicy), $this->policy),
                $policy, new TransferBudget($profile->maxEntityBytes, $profile->maxEntityBytes), $payload);
        }
        if (($probeOnly || $selection instanceof TransportDeferred)
            && $idempotency === IdempotencyClass::ReadOnly && in_array($method, ['GET', 'HEAD'], true)) {
            // A real read-only input is the bounded canary. Ordinary backlog remains denied;
            // the composite probe claim below is the only authority for the initial send.
            foreach ($routePolicy->bindings as $binding) {
                $result = $this->bridge->run($callerCode, $platformId, $controls, $deadlineSeconds, $hash,
                    function (OutboundExecutionContext $context) use ($plan, $binding, $routePolicy, $policy, $payload, $profile): TransportOutcome {
                        $claim = $this->probes->claim($plan, $context,
                            new NetworkRouteSelection($binding->id, $routePolicy->profileId, $binding->credentialVersion));
                        if (! $claim instanceof ProbeClaim) {
                            return $claim;
                        }
                        $single = new OutboundRequestPlan($plan->physicalRequestId, $plan->targetId, $plan->method,
                            $plan->url, $plan->profile, $plan->trafficClass, $plan->idempotency, $plan->bodyHash, 1, $plan->adapterClaim);

                        return $this->executor->execute($single, $context,
                            new ScheduledAttemptGate($this->permits, $this->routes->outboundRoutes(
                                new RoutePolicy([$binding], $routePolicy->profileId), $claim), $this->policy),
                            $policy, new TransferBudget($profile->maxEntityBytes, $profile->maxEntityBytes), $payload);
                    }, $checkpoint, $providerKey, OutboundOperationKind::Probe);
                if ($result instanceof TransportSucceeded) {
                    return $result;
                }
                // A physical probe is bounded to one selected route per logical call. Failed
                // claims have no demand, so another exact member may still be evaluated safely.
                if (LedgerRow::one('SELECT request_id FROM prc_request_attempts WHERE request_id = ?', [$plan->physicalRequestId->value]) !== null) {
                    return $result;
                }
            }

            return $selection instanceof TransportDeferred || $selection instanceof TransportBlocked
                ? $selection : new TransportBlocked(NetworkBlockedReason::OwnerLost);
        }

        return $this->bridge->run($callerCode, $platformId, $controls, $deadlineSeconds, $hash,
            fn (OutboundExecutionContext $context): TransportOutcome => $this->executor->execute(
                $plan, $context, new ScheduledAttemptGate($this->permits, $this->routes->outboundRoutes($routePolicy), $this->policy),
                $policy, new TransferBudget($profile->maxEntityBytes, $profile->maxEntityBytes), $payload),
            $checkpoint, $providerKey);
    }

    /**
     * Legacy response adapter. All physical retries, redirects, health and rate accounting stay in
     * OutboundExecutor. A caller cannot invent an endpoint policy when the catalog is missing.
     *
     * @param  list<string>  $controls
     */
    public function request(string $callerCode, ?int $platformId, ?ProviderKey $providerKey, string $capability,
        SafeUrl $url, TrafficClass $trafficClass, RoutePolicy $routePolicy, array $controls, int $deadlineSeconds,
        string $method = 'GET', HttpRequestPayload $payload = new HttpRequestPayload,
        IdempotencyClass $idempotency = IdempotencyClass::ReadOnly, ?Closure $checkpoint = null, ?int $sendLimit = null, ?OutboundExecutionContext $context = null): \Illuminate\Http\Client\Response
    {
        try {
            return $this->requestResponse($callerCode, $platformId, $providerKey, $capability, $url, $trafficClass,
                $routePolicy, $controls, $deadlineSeconds, $method, $payload, $idempotency, $checkpoint, $sendLimit, $context);
        } catch (\Throwable $failure) {
            if (! DatabaseFailureClassifier::isTransient($failure)
                && ! $failure instanceof DependencyUnavailable) {
                throw $failure;
            }
            throw OutboundUnavailable::dependency();
        }
    }

    /** @param list<string> $controls */
    private function requestResponse(string $callerCode, ?int $platformId, ?ProviderKey $providerKey, string $capability,
        SafeUrl $url, TrafficClass $trafficClass, RoutePolicy $routePolicy, array $controls, int $deadlineSeconds,
        string $method, HttpRequestPayload $payload, IdempotencyClass $idempotency, ?Closure $checkpoint, ?int $sendLimit, ?OutboundExecutionContext $context): \Illuminate\Http\Client\Response
    {
        $target = $this->catalog->targetForCall($platformId, $providerKey?->value, $capability, $url->origin->value());
        if ($target === null) {
            throw new OutboundUnavailable(new TransportBlocked(NetworkBlockedReason::ConfigurationHold));
        }
        $definition = json_decode($target->string('endpoint_policy'), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($definition) || ! isset($definition['profiles']) || ! is_array($definition['profiles'])) {
            throw new OutboundUnavailable(new TransportBlocked(NetworkBlockedReason::ConfigurationHold));
        }
        $profiles = array_values(array_filter($definition['profiles'], static fn (mixed $profile): bool => ! is_array($profile) || ($profile['code'] ?? null) !== 'network.probe'));
        if (count($profiles) !== 1 || ! is_array($profiles[0]) || ! is_string($profiles[0]['code'] ?? null)) {
            throw new OutboundUnavailable(new TransportBlocked(NetworkBlockedReason::ConfigurationHold));
        }
        $origins = $this->catalog->originsForOwner($platformId, $providerKey?->value, $capability);
        $result = $this->execute(callerCode: $callerCode, platformId: $platformId, providerKey: $providerKey,
            targetKey: $target->string('target_key'), profileCode: $profiles[0]['code'], url: $url,
            trafficClass: $trafficClass, routePolicy: $routePolicy, controls: $controls,
            deadlineSeconds: $deadlineSeconds, method: $method, payload: $payload,
            idempotency: $idempotency, checkpoint: $checkpoint, urlPolicy: $this->urls ?? new UrlPolicy($origins), sendLimit: $sendLimit, context: $context);
        if ($result instanceof TransportBlocked || $result instanceof TransportDeferred) {
            throw new OutboundUnavailable($result);
        }
        if (! $result instanceof TransportSucceeded) {
            throw new \LogicException('Unknown outbound transport outcome.');
        }
        if ($context !== null) {
            $context->recordSuccessfulRequest($result->physicalRequestId ?? throw new \LogicException('Missing final physical request identity.'));
        }
        $response = $result->response;
        $body = match (true) {
            $response->body instanceof DecodedTextBody => $response->body->utf8,
            $response->body instanceof BinaryBodyArtifact => new ArtifactResponseStream($response->body->artifact),
            default => throw new \LogicException('Unsupported outbound response body.'),
        };

        return new \Illuminate\Http\Client\Response(new Response($response->status, $response->headers, $body));
    }

    /** Explicit configured direct egress only; no fallback from an unavailable profile. */
    public function direct(): RoutePolicy
    {
        $route = LedgerRow::one("SELECT id, credential_version FROM prc_network_routes WHERE route_key = ? AND kind = 'direct'", ['direct:'.$this->policy->directEgressId]);
        if ($route === null) {
            throw new \LogicException('Configured direct egress is not installed; run platform:sync-catalog.');
        }

        return new RoutePolicy([new HttpRouteBinding(new ProcessUuid($route->string('id')), credentialVersion: $route->int('credential_version'))]);
    }
}
