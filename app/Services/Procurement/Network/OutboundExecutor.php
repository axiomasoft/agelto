<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Network\BinaryBodyArtifact;
use App\Dto\Procurement\Network\HttpAttemptResult;
use App\Dto\Procurement\Network\HttpRequestPayload;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\RetryRoutePreference;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Network\TransferBudget;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Network\TransportOutcome;
use App\Dto\Procurement\Network\TransportSucceeded;
use App\Dto\Procurement\Process\Duration;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\RetryAt;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Network\TransportFailure;
use App\Exceptions\Procurement\Network\OutboundOwnershipLost;
use App\Exceptions\Procurement\Network\TransportInterrupted;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Exceptions\Procurement\Process\ExecutionInterrupted;
use App\Exceptions\Procurement\Process\LeaseLost;
use App\Exceptions\Procurement\Process\SliceExhausted;
use App\Services\Procurement\Network\Contracts\OutboundAttemptGate;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** One physical-attempt loop. No activation/binding of legacy callers before P2.6. */
final readonly class OutboundExecutor
{
    public function __construct(private HttpTransport $transport, private RetryPolicy $retryPolicy = new RetryPolicy) {}

    public function execute(OutboundRequestPlan $plan, ExecutionContext|OutboundExecutionContext $context, OutboundAttemptGate $gate, UrlPolicy $policy, TransferBudget $budget, HttpRequestPayload $payload = new HttpRequestPayload): TransportOutcome
    {
        try {
            return $this->run($plan, $context, $gate, $policy, $budget, $payload);
        } catch (OutboundOwnershipLost|LeaseLost) {
            return new TransportBlocked(NetworkBlockedReason::OwnerLost);
        } catch (SliceExhausted) {
            return $this->deferred(NetworkDeferralReason::SliceExhausted, 1);
        } catch (DependencyUnavailable) {
            return $this->deferred(NetworkDeferralReason::DependencyUnavailable, $this->retryPolicy->dependencySeconds);
        }
    }

    private function run(OutboundRequestPlan $plan, ExecutionContext|OutboundExecutionContext $context, OutboundAttemptGate $gate, UrlPolicy $policy, TransferBudget $budget, HttpRequestPayload $payload): TransportOutcome
    {
        if (($plan->bodyHash?->hex) !== ($payload->body === null ? null : hash('sha256', $payload->body))) {
            throw new \InvalidArgumentException('Request body does not match pinned hash.');
        }
        $url = $plan->url;
        $targetId = $plan->targetId;
        $tried = [];
        $current = null;
        $preferUntried = false;
        $hops = 0;
        $sends = 0;
        for ($attempt = 1; $attempt <= $plan->maxAttempts(); $attempt++) {
            while (true) {
                $context->tick();
                if (! $context->canStart(Duration::seconds($plan->profile->totalSeconds))) {
                    return $this->deferred(NetworkDeferralReason::SliceExhausted, 1);
                }
                try {
                    $resolved = $policy->resolve($url, $context, $plan->profile->connectSeconds);
                } catch (\InvalidArgumentException) {
                    return new TransportBlocked(NetworkBlockedReason::UrlPolicyDenied);
                } catch (OutboundOwnershipLost|ExecutionInterrupted $exit) {
                    throw $exit;
                } catch (\RuntimeException) {
                    return $this->deferred(NetworkDeferralReason::DependencyUnavailable, $this->retryPolicy->dependencySeconds);
                }
                $context->tick();
                if (! $context->canStart(Duration::seconds($plan->profile->totalSeconds))) {
                    return $this->deferred(NetworkDeferralReason::SliceExhausted, 1);
                }
                $physical = new OutboundRequestPlan(
                    $sends === 0 ? $plan->physicalRequestId : new ProcessUuid((string) Str::uuid()),
                    $targetId, $plan->method, $url, $plan->profile, $plan->trafficClass, $plan->idempotency, $plan->bodyHash,
                );
                $authorized = $gate->authorize($physical, $context, new RetryRoutePreference($tried, $current, $preferUntried, $plan->profile->distinctRoutes));
                if ($authorized instanceof TransportDeferred || $authorized instanceof TransportBlocked) {
                    return $authorized;
                }
                $route = $authorized->route->id;
                if ((! in_array($route->value, $tried, true) && count($tried) >= $plan->profile->distinctRoutes)
                    || $authorized->plan !== $physical) {
                    throw new \LogicException('Gate returned a mismatched physical request or route budget.');
                }
                if (! in_array($route->value, $tried, true)) {
                    $tried[] = $route->value;
                }
                $current = $route;
                // Gate can wait; full timeout must STILL fit at the last cooperative point.
                try {
                    $context->tick();
                    if (! $context->canStart(Duration::seconds($plan->profile->totalSeconds))) {
                        $gate->record($authorized, new HttpAttemptResult(null, TransportFailure::Cancelled, 0, 0));

                        return $this->deferred(NetworkDeferralReason::SliceExhausted, 1);
                    }
                } catch (OutboundOwnershipLost|ExecutionInterrupted $exit) {
                    $gate->record($authorized, new HttpAttemptResult(null, TransportFailure::Cancelled, 0, 0));
                    throw $exit;
                }
                $sends++;
                try {
                    $result = $this->transport->sendOnce($authorized, $resolved, $payload, $context, $budget);
                } catch (TransportInterrupted $exit) {
                    $gate->record($authorized, $exit->result);
                    throw $exit->interruption;
                }
                $quota = $gate->record($authorized, $result);
                if ($result->response?->body instanceof BinaryBodyArtifact
                    && in_array($result->response->status, [301, 302, 303, 307, 308, 407, 429, 502, 503, 504], true)) {
                    Storage::disk($result->response->body->artifact->disk)->delete($result->response->body->artifact->key);
                }
                if ($quota !== null) {
                    return $quota;
                }
                if ($result->failure !== null) {
                    if ($result->failure === TransportFailure::Storage) {
                        return $this->deferred(NetworkDeferralReason::DependencyUnavailable, $this->retryPolicy->dependencySeconds);
                    }
                    if ($result->failure === TransportFailure::Configuration) {
                        return new TransportBlocked(NetworkBlockedReason::ConfigurationHold);
                    }
                    if ($result->failure === TransportFailure::ProxyAuth) {
                        $preferUntried = true;
                        break;
                    }
                    if (! $result->failure->retryable()) {
                        return new TransportBlocked($result->failure === TransportFailure::Tls
                            ? NetworkBlockedReason::ConfigurationHold : NetworkBlockedReason::PolicyReview);
                    }
                    $preferUntried = count($tried) < $plan->profile->distinctRoutes;
                    break;
                }
                $response = $result->response ?? throw new \LogicException('Missing physical response.');
                if ($response->status === 407) {
                    $preferUntried = true;
                    break;
                }
                if (in_array($response->status, [502, 503, 504], true)) {
                    $preferUntried = false;
                    break;
                }
                if (in_array($response->status, [301, 302, 303, 307, 308], true)) {
                    if ($hops >= $plan->profile->redirects || $plan->method === 'POST') {
                        return new TransportBlocked(NetworkBlockedReason::UrlPolicyDenied);
                    }
                    $location = $response->header('location');
                    if ($location === null) {
                        return new TransportBlocked(NetworkBlockedReason::UrlPolicyDenied);
                    }
                    try {
                        $next = new SafeUrl((string) UriResolver::resolve(new Uri($url->reveal()), new Uri($location)));
                    } catch (\InvalidArgumentException) {
                        return new TransportBlocked(NetworkBlockedReason::UrlPolicyDenied);
                    }
                    if ($url->origin->scheme === 'https' && $next->origin->scheme !== 'https') {
                        return new TransportBlocked(NetworkBlockedReason::UrlPolicyDenied);
                    }
                    if (! $url->origin->equals($next->origin)) {
                        try {
                            $policy->resolve($next, $context, $plan->profile->connectSeconds);
                        } catch (OutboundOwnershipLost|ExecutionInterrupted $exit) {
                            throw $exit;
                        } catch (\InvalidArgumentException|\RuntimeException) {
                            return new TransportBlocked(NetworkBlockedReason::UrlPolicyDenied);
                        }
                        $redirectTarget = $gate->targetForRedirect($physical, $next);
                        if ($redirectTarget instanceof TransportBlocked) {
                            return $redirectTarget;
                        }
                        $targetId = $redirectTarget;
                        $payload = $payload->acrossOrigin();
                    }
                    $url = $next;
                    $hops++;
                    $preferUntried = false;

                    continue;
                }

                return new TransportSucceeded($response);
            }
            if ($attempt < $plan->maxAttempts() && ! $preferUntried) {
                $delay = random_int(0, $plan->profile->delaysMs[$attempt - 1]);
                if (! $context->canStart(new Duration($delay + $plan->profile->totalSeconds * 1000))) {
                    return $this->deferred(NetworkDeferralReason::SliceExhausted, 1);
                }
                $until = hrtime(true) + $delay * 1_000_000;
                while (($remaining = $until - hrtime(true)) > 0) {
                    usleep((int) min(200_000, intdiv($remaining, 1000) + 1));
                    $context->tick();
                }
            }
        }

        return $this->deferred(NetworkDeferralReason::AttemptsExhausted, $this->retryPolicy->attemptsExhaustedSeconds);
    }

    private function deferred(NetworkDeferralReason $reason, int $seconds): TransportDeferred
    {
        return new TransportDeferred($reason, new RetryAt(new \DateTimeImmutable('+'.$seconds.' seconds')));
    }
}
