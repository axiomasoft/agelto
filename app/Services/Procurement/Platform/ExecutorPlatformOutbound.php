<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform;

use App\Dto\Procurement\Network\Control\OutboundExecutionContext;
use App\Dto\Procurement\Network\Control\OutboundRequestPlan;
use App\Dto\Procurement\Network\Http\TransferBudget;
use App\Dto\Procurement\Network\Outcome\TransportBlocked;
use App\Dto\Procurement\Network\Outcome\TransportDeferred;
use App\Dto\Procurement\Network\Outcome\TransportOutcome;
use App\Dto\Procurement\Network\Outcome\TransportSucceeded;
use App\Dto\Procurement\Process\Authority\ControlSnapshot;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\RetryAt;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Network\OutboundOperationKind;
use App\Repositories\Procurement\Network\OutboundOperationRepository;
use App\Services\Procurement\Network\NetworkOwnerFence;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Network\UrlPolicy;
use App\Services\Procurement\Platform\Contracts\PlatformOutbound;
use App\Services\Procurement\Process\Infrastructure\ProcessTransaction;
use App\Services\Procurement\Proxyma\Caller\CallerGate;
use App\Services\Procurement\Proxyma\Caller\CallerOutbound;
use App\Services\Procurement\Proxyma\Caller\CallerSendFacts;
use App\Services\Procurement\Proxyma\Catalog\ProfileBindings;
use Illuminate\Support\Str;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Domain\Admission\AdmissionRejected;

final readonly class ExecutorPlatformOutbound implements PlatformOutbound
{
    public function __construct(
        private CallerOutbound $executor,
        private CallerGate $gate,
        private UrlPolicy $urls,
        private TransferBudget $budget,
        private ?ProfileBindings $routes = null,
        private ?int $platformId = null,
    ) {}

    public function execute(OutboundRequestPlan $plan, ExecutionContext|OutboundExecutionContext $context): TransportOutcome
    {
        if (! $context instanceof ExecutionContext || $this->routes === null || $this->platformId === null
            || $plan->idempotency !== IdempotencyClass::ReadOnly || ! in_array($plan->method, ['GET', 'HEAD'], true)) {
            return $this->executor->execute($plan, $context, $this->gate, $this->urls, $this->budget);
        }
        $routes = $this->routes;
        try {
            ['routes' => $routes, 'profile' => $profile] = CallerSendFacts::probeRoutes($routes, $plan, $context);
        } catch (AdmissionRejected|AuthorizationDenied|\DomainException) {
            return new TransportBlocked(NetworkBlockedReason::ConfigurationHold);
        }
        $selection = CallerSendFacts::needsProbe($routes, $plan->targetId->value, $profile)
            ? new TransportDeferred(NetworkDeferralReason::TargetCooldown, new RetryAt(new \DateTimeImmutable('+1 second'))) : null;
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
        $policy = app(OutboundPolicy::class);
        $deadline = max($seconds, $plan->profile->totalSeconds + 60);
        foreach ($routes->bindings as $binding) {
            $token = new ProcessUuid((string) Str::uuid());
            $probe = $tx->run(fn () => $operations->open(new ProcessUuid((string) Str::uuid()), $token,
                OutboundOperationKind::Probe, $this->platformId, 'document.download_canary', $context->lease()->inputHash,
                $controls, $seconds, $deadline, probeToken: $token, parentAttemptId: $context->lease()->attemptId));
            $owner = new OutboundExecutionContext($probe,
                fn ($handle) => $tx->run(fn () => $operations->renew($handle, $seconds)), $context->tick(...), $seconds, 15);
            try {
                $single = new OutboundRequestPlan($plan->physicalRequestId, $plan->targetId, $plan->method,
                    $plan->url, $plan->profile, $plan->trafficClass, $plan->idempotency, $plan->bodyHash, 1, $plan->adapterClaim);
                $gate = new CallerGate(new ProfileBindings([$binding], $routes->profileId));
                $result = $this->executor->execute($single, $owner, $gate, $this->urls, $this->budget);
            } finally {
                $tx->run(fn () => $operations->close($probe));
            }
            if ($result instanceof TransportSucceeded) {
                return $result;
            }
            if (($plan->sendLimit === null || $plan->sendLimit > 1)
                && ! CallerSendFacts::needsProbe($routes, $plan->targetId->value, $profile)) {
                $next = new OutboundRequestPlan(ProcessUuid::random(), $plan->targetId, $plan->method,
                    $plan->url, $plan->profile, $plan->trafficClass, $plan->idempotency, $plan->bodyHash,
                    $plan->sendLimit === null ? null : $plan->sendLimit - 1, $plan->adapterClaim);

                return $this->executor->execute($next, $context, $this->gate, $this->urls, $this->budget);
            }
            if (CallerSendFacts::operationSends($probe->id->value) > 0) {
                return $result;
            }
        }

        return $selection;
    }
}
