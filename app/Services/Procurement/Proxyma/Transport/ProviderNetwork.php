<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma\Transport;

use App\Dto\Procurement\Network\Control\OutboundRequestPlan;
use App\Dto\Procurement\Network\Control\ProviderKey;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Services\Llm\Execution\ProviderWireRequest;
use App\Services\Procurement\Proxyma\Caller\AdapterSendGuard;
use App\Services\Procurement\Proxyma\Catalog\ProcessOwnerAuthority;
use App\Services\Procurement\Proxyma\Catalog\TargetCatalogAdapter;
use App\Support\Time\Monotonic;
use Proxyma\Application\Admission\DemandQueue;
use Proxyma\Application\Admission\OperationCommands;
use Proxyma\Application\Admission\ReservationService;
use Proxyma\Application\Execution\ProxyResolver;
use Proxyma\Application\Execution\WireSealer;
use Proxyma\Application\Policy\PolicyResolver;
use Proxyma\Application\Routing\RouteSelector;
use Proxyma\Contracts\CatalogQueries;
use Proxyma\Contracts\DestinationResolver;
use Proxyma\Contracts\SecretResolver;
use Proxyma\Contracts\TlsTrustPolicy;
use Proxyma\Contracts\WorkBudget;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\DemandWait;
use Proxyma\Domain\Admission\ReservationDeferred;
use Proxyma\Domain\Catalog\ProxyProtocol;
use Proxyma\Domain\Catalog\RouteKind;
use Proxyma\Domain\Execution\Duration;
use Proxyma\Domain\Execution\ExecutionContext as PackageContext;
use Proxyma\Domain\Execution\IdempotencyClass;
use Proxyma\Domain\Execution\OperationCorrelation;
use Proxyma\Domain\Execution\PreparedHeaders;
use Proxyma\Domain\Execution\RequestTimeoutPolicy;
use Proxyma\Domain\Execution\ResponseProfile;
use Proxyma\Domain\Execution\RouteSelectionContext;
use Proxyma\Domain\Execution\SafeUrl;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Execution\WireBody;
use Proxyma\Domain\Execution\WireRequest;
use Proxyma\Domain\Policy\SubjectChain;
use Proxyma\Domain\Policy\SubjectRef;
use Proxyma\Domain\Policy\TenantKey;

/** Подготовка выполняется до process/finance locks; provider wire принадлежит Agelto. */
final readonly class ProviderNetwork
{
    public function __construct(
        private ProcessOwnerAuthority $owners, private TargetCatalogAdapter $targets,
        private AdapterSendGuard $guard, private CatalogQueries $catalog, private PolicyResolver $policies,
        private RouteSelector $selector, private DestinationResolver $destinations,
        private ProxyResolver $proxies, private SecretResolver $secrets, private TlsTrustPolicy $tls,
        private WireSealer $sealer, private OperationCommands $operations,
        private ReservationService $reservations, private DemandQueue $demands,
    ) {}

    /** @param \Closure(): void $revalidate */
    public function prepare(OutboundRequestPlan $plan, ProviderWireRequest $provider, ExecutionContext $context,
        #[\SensitiveParameter] string $apiKey, int $waitDeadlineNs, \Closure $revalidate): PreparedProviderNetwork
    {
        $tenant = TenantKey::system();
        $source = $this->owners->connection()->table('prc_source_targets')->where('id', $plan->targetId->value)
            ->whereNull('platform_id')->where('provider_key', $provider->operation->context->provider)->whereNull('retired_at')->first();
        if ($source === null || ! is_string($source->target_key)) {
            throw new AdmissionRejected('provider_target_mismatch');
        }
        $target = $this->targets->provider(new ProviderKey($provider->operation->context->provider), $source->target_key);
        $subjects = new SubjectChain(new SubjectRef($tenant, 'provider', $provider->operation->context->provider));
        $policy = $this->policies->resolveEffective($subjects, $target->id, 'llm', panelKey: 'agelto.provider', bypassCache: true);
        $profileId = $policy->routeProfileId ?? throw new AdmissionRejected('provider_profile_missing');
        $owner = $this->owners->reference($context->lease(), new ProviderKey($provider->operation->context->provider));
        $operation = new OperationCorrelation(new Uuid($plan->physicalRequestId->value), $owner,
            $context->lease()->leaseUntil, $plan->physicalRequestId->value);
        $totalMs = min($policy->timeouts->totalTimeoutMs, $plan->profile->totalSeconds * 1000);
        $timeouts = new RequestTimeoutPolicy(
            min($totalMs, $policy->timeouts->connectTimeoutMs, $plan->profile->connectSeconds * 1000),
            min($totalMs, $policy->timeouts->readTimeoutMs, $plan->profile->lowSpeedSeconds * 1000),
            $totalMs,
            $policy->timeouts->cleanupMarginMs,
        );
        $work = new class($context) implements WorkBudget
        {
            public function __construct(private ExecutionContext $context) {}

            public function canStart(Duration $required): bool
            {
                $this->context->tick();

                return $this->context->canStart(new \App\Dto\Procurement\Process\Primitives\Duration($required->milliseconds));
            }
        };
        $packageContext = new PackageContext($operation, $subjects, new Duration($timeouts->totalTimeoutMs + $timeouts->cleanupMarginMs),
            Monotonic::nanoseconds() + ($timeouts->totalTimeoutMs + $timeouts->cleanupMarginMs) * 1_000_000);
        $request = new WireRequest($operation->operationId, new Uuid($plan->physicalRequestId->value), $target->id,
            $policy->effectiveRevision, $provider->method, new SafeUrl($provider->endpoint), $timeouts, $operation->deadlineAt,
            new PreparedHeaders($provider->preparedHeaders($apiKey)),
            in_array($provider->method, ['GET', 'HEAD'], true) ? null : WireBody::fromString($provider->body),
            $provider->method === 'GET' ? IdempotencyClass::ReadOnly : IdempotencyClass::AmbiguousWrite,
            trafficClass: 'llm', responseProfile: new ResponseProfile('raw', $plan->profile->maxEntityBytes,
                $plan->profile->maxEntityBytes, $plan->profile->maxEntityBytes));
        $destination = $this->destinations->resolve($request->url, $packageContext, $timeouts->connectTimeoutMs, $target);
        $selected = $this->selector->select($tenant, $profileId, $policy, $request, new RouteSelectionContext);
        $route = $selected->route ?? throw new AdmissionRejected('provider_route_unavailable');
        $selection = $selected->selection ?? throw new AdmissionRejected('provider_route_unavailable');
        $proxy = null;
        $credential = null;
        $proxyTls = null;
        if ($route->kind === RouteKind::Proxy) {
            $endpoint = $route->endpointId === null ? null : $this->catalog->findEndpoint($route->tenantKey, $route->endpointId);
            if ($endpoint === null || $endpoint->isRetired()) {
                throw new AdmissionRejected('provider_proxy_missing');
            }
            $proxy = $this->proxies->resolve($endpoint, $timeouts->connectTimeoutMs);
            if ($endpoint->credential !== null) {
                $credential = $this->secrets->resolve($endpoint->credential, $route->tenantKey)
                    ?? throw new AdmissionRejected('provider_proxy_credential_missing');
            }
            if ($endpoint->protocol === ProxyProtocol::Https) {
                $proxyTls = $this->tls->proxyConfigurationFor($endpoint->host);
            }
        }
        $wire = $this->sealer->seal($request, $route, $destination,
            $this->tls->tlsConfigurationFor($request->url->origin, $target->id), $credential, $proxyTls, $proxy);
        $this->guard->prepare($plan);
        $this->operations->create($operation, $profileId, $target->id, $timeouts, new Uuid($context->lease()->attemptId->value));
        $input = $this->reservations->prepare($wire->request, $operation, $selection, $policy, $work);
        $ticket = $this->reservations->enqueue($input, $work);
        if ($ticket instanceof DemandWait) {
            throw new ReservationDeferred($ticket);
        }
        $permit = $this->reservations->reserve($ticket, $input);
        try {
            while ($permit instanceof DemandWait && Monotonic::nanoseconds() < $waitDeadlineNs) {
                usleep(100_000);
                $revalidate();
                $permit = $this->reservations->reserve($ticket, $input);
            }
            if ($permit instanceof DemandWait) {
                throw new ReservationDeferred($permit);
            }
        } finally {
            if ($permit instanceof DemandWait) {
                $this->demands->cancel($tenant, $operation->operationId, $ticket->demandId);
            }
        }

        return new PreparedProviderNetwork($input, $permit, $wire);
    }
}
