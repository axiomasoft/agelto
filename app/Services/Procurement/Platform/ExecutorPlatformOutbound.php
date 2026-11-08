<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform;

use App\Dto\Procurement\Network\NetworkRouteSelection;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\ProbeClaim;
use App\Dto\Procurement\Network\RetryRoutePreference;
use App\Dto\Procurement\Network\SelectedNetworkRoute;
use App\Dto\Procurement\Network\TransferBudget;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Network\TransportOutcome;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Dto\Procurement\Process\ControlSnapshot;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\RetryAt;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Network\OutboundOperationKind;
use App\Repositories\Procurement\Network\NetworkPermitRepository;
use App\Repositories\Procurement\Network\OutboundOperationRepository;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Network\Contracts\OutboundAttemptGate;
use App\Services\Procurement\Network\NetworkOwnerFence;
use App\Services\Procurement\Network\OutboundExecutor;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Network\ProbeScheduler;
use App\Services\Procurement\Network\ScheduledAttemptGate;
use App\Services\Procurement\Network\UrlPolicy;
use App\Services\Procurement\Platform\Contracts\PlatformOutbound;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Proxy\ProxyResolver;
use App\Services\Procurement\Proxy\RoutePolicy;
use Illuminate\Support\Str;

final readonly class ExecutorPlatformOutbound implements PlatformOutbound
{
    public function __construct(
        private OutboundExecutor $executor,
        private OutboundAttemptGate $gate,
        private UrlPolicy $urls,
        private TransferBudget $budget,
        private ?RoutePolicy $routes = null,
        private ?int $platformId = null,
    ) {}

    public function execute(OutboundRequestPlan $plan, ExecutionContext|OutboundExecutionContext $context): TransportOutcome
    {
        if (! $context instanceof ExecutionContext || $this->routes === null || $this->platformId === null
            || $plan->idempotency !== IdempotencyClass::ReadOnly || ! in_array($plan->method, ['GET', 'HEAD'], true)) {
            return $this->executor->execute($plan, $context, $this->gate, $this->urls, $this->budget);
        }
        $resolver = app(ProxyResolver::class);
        $preference = new RetryRoutePreference([], null, true, $plan->profile->distinctRoutes);
        $selection = $resolver->outboundRoutes($this->routes)->select($plan, $preference);
        if (! $selection instanceof TransportDeferred) {
            return $this->executor->execute($plan, $context, $this->gate, $this->urls, $this->budget);
        }
        $seconds = min(300, (int) floor($context->remainingSlice()->milliseconds / 1000) - 15);
        if ($seconds < 1) {
            return new TransportDeferred(NetworkDeferralReason::SliceExhausted, new RetryAt(new \DateTimeImmutable('+1 second')));
        }
        $authority = app(NetworkOwnerFence::class)->snapshot($context->lease())
            ?? throw new \LogicException('Missing download authority.');
        $controls = array_values(array_unique([...array_map(fn (ControlSnapshot $c): string => $c->scopeKey, $authority->controls), 'monitoring']));
        $tx = app(ProcessTransaction::class);
        $operations = app(OutboundOperationRepository::class);
        $probes = app(ProbeScheduler::class);
        $permits = app(NetworkPermitRepository::class);
        $policy = app(OutboundPolicy::class);
        $deadline = max($seconds, $plan->profile->totalSeconds + 60);
        foreach ($this->routes->bindings as $binding) {
            $token = new ProcessUuid((string) Str::uuid());
            $probe = $tx->run(fn () => $operations->open(new ProcessUuid((string) Str::uuid()), $token,
                OutboundOperationKind::Probe, $this->platformId, 'document.download_canary', $context->lease()->inputHash,
                $controls, $seconds, $deadline, probeToken: $token, parentAttemptId: $context->lease()->attemptId));
            $owner = new OutboundExecutionContext($probe,
                fn ($handle) => $tx->run(fn () => $operations->renew($handle, $seconds)), $context->tick(...), $seconds, 15);
            try {
                $claim = $probes->claim($plan, $owner, new NetworkRouteSelection($binding->id, $this->routes->profileId, $binding->credentialVersion));
                if (! $claim instanceof ProbeClaim) {
                    continue;
                }
                $single = new OutboundRequestPlan($plan->physicalRequestId, $plan->targetId, $plan->method,
                    $plan->url, $plan->profile, $plan->trafficClass, $plan->idempotency, $plan->bodyHash, 1, $plan->adapterClaim);
                $gate = new ScheduledAttemptGate($permits, $resolver->outboundRoutes(new RoutePolicy([$binding], $this->routes->profileId), $claim), $policy);
                $result = $this->executor->execute($single, $owner, $gate, $this->urls, $this->budget);
            } finally {
                $tx->run(fn () => $operations->close($probe));
            }
            if ($result instanceof TransportSucceeded) {
                return $result;
            }
            if (($plan->sendLimit === null || $plan->sendLimit > 1)
                && $resolver->outboundRoutes($this->routes)->select($plan, $preference) instanceof SelectedNetworkRoute) {
                $next = new OutboundRequestPlan(ProcessUuid::random(), $plan->targetId, $plan->method,
                    $plan->url, $plan->profile, $plan->trafficClass, $plan->idempotency, $plan->bodyHash,
                    $plan->sendLimit === null ? null : $plan->sendLimit - 1, $plan->adapterClaim);

                return $this->executor->execute($next, $context, $this->gate, $this->urls, $this->budget);
            }
            if (LedgerRow::one('SELECT request_id FROM prc_request_attempts WHERE request_id = ?', [$plan->physicalRequestId->value]) !== null) {
                return $result;
            }
        }

        return $selection;
    }
}
