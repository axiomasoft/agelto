<?php

declare(strict_types=1);

namespace App\Services\Procurement\Network;

use App\Dto\Procurement\Network\BinaryBodyArtifact;
use App\Dto\Procurement\Network\DecodedTextBody;
use App\Dto\Procurement\Network\FinalHttpResponseEvidence;
use App\Dto\Procurement\Network\HttpAttemptResult;
use App\Dto\Procurement\Network\HttpRequestPayload;
use App\Dto\Procurement\Network\NetworkDemandTicket;
use App\Dto\Procurement\Network\NetworkPermitWait;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\ProviderKey;
use App\Dto\Procurement\Network\ReservedProviderNetwork;
use App\Dto\Procurement\Network\RetryRoutePreference;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Network\ScheduledNetworkPermit;
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
use App\Enums\Procurement\Network\TrafficClass;
use App\Enums\Procurement\Network\TransportFailure;
use App\Exceptions\Procurement\Network\OutboundOwnershipLost;
use App\Exceptions\Procurement\Network\TransportInterrupted;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Exceptions\Procurement\Process\ExecutionInterrupted;
use App\Exceptions\Procurement\Process\LeaseLost;
use App\Exceptions\Procurement\Process\SliceExhausted;
use App\Repositories\Procurement\Network\NetworkPermitRepository;
use App\Services\Procurement\Network\Contracts\OutboundAttemptGate;
use App\Services\Procurement\Network\Contracts\OutboundRouteSelector;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Sole physical-attempt loop for Process, bounded probes and surviving legacy callers. */
final readonly class OutboundExecutor
{
    public function __construct(private HttpTransport $transport, private NetworkPermitRepository $permits, private RetryPolicy $retryPolicy = new RetryPolicy) {}

    /** Authorization API for the financial owner: reserves once, never claims or sends/retries. */
    public function reserveProviderSend(OutboundRequestPlan $plan, ExecutionContext $context, OutboundRouteSelector $routes,
        UrlPolicy $policy, ProviderKey $provider, \Closure $revalidate): ReservedProviderNetwork|TransportDeferred|TransportBlocked
    {
        $context->tick();
        $revalidate();
        try {
            $resolved = $policy->resolve($plan->url, $context, $plan->profile->connectSeconds);
        } catch (\InvalidArgumentException) {
            return new TransportBlocked(NetworkBlockedReason::UrlPolicyDenied);
        } catch (OutboundOwnershipLost|ExecutionInterrupted $exit) {
            throw $exit;
        } catch (\RuntimeException) {
            return $this->deferred(NetworkDeferralReason::DependencyUnavailable, $this->retryPolicy->dependencySeconds);
        }
        $route = $routes->select($plan, new RetryRoutePreference([], null, false, 1));
        if ($route instanceof TransportBlocked || $route instanceof TransportDeferred) {
            return $route;
        }
        $ticket = $this->permits->register($plan, $context, $route->selection, $provider);
        if (! $ticket instanceof NetworkDemandTicket) {
            return $ticket;
        }
        $waitUntil = hrtime(true) + (new OutboundPolicy(app('config')))->maxInlineWaitSeconds * 1_000_000_000;
        try {
            do {
                $context->tick();
                $revalidate();
                $permit = $this->permits->reserve($ticket, $context, $provider);
                if ($permit instanceof NetworkPermitWait) {
                    if (hrtime(true) >= $waitUntil) {
                        $this->permits->abandon($ticket, $context);

                        return new TransportDeferred($permit->reason, $permit->retryAt);
                    }
                    // One second maximum; next iteration revalidates finance and Process authority.
                    $delayUs = max(100_000, min(1_000_000, (int) (((float) $permit->retryAt->value->format('U.u') - microtime(true)) * 1_000_000)));
                    usleep($delayUs);
                }
            } while ($permit instanceof NetworkPermitWait);
        } catch (\Throwable $exception) {
            $this->permits->abandon($ticket, $context);
            throw $exception;
        }

        return $permit instanceof ScheduledNetworkPermit ? new ReservedProviderNetwork($permit, $route, $resolved) : $permit;
    }

    public function execute(OutboundRequestPlan $plan, ExecutionContext|OutboundExecutionContext $context, OutboundAttemptGate $gate, UrlPolicy $policy, TransferBudget $budget, HttpRequestPayload $payload = new HttpRequestPayload): TransportOutcome
    {
        $lastRequestId = null;
        $finalResponse = null;
        try {
            $outcome = $this->run($plan, $context, $gate, $policy, $budget, $payload, $lastRequestId, $finalResponse);
        } catch (OutboundOwnershipLost|LeaseLost) {
            $outcome = new TransportBlocked(NetworkBlockedReason::OwnerLost);
        } catch (SliceExhausted) {
            $outcome = $this->deferred(NetworkDeferralReason::SliceExhausted, 1);
        } catch (DependencyUnavailable) {
            $outcome = $this->deferred(NetworkDeferralReason::DependencyUnavailable, $this->retryPolicy->dependencySeconds);
        }

        return match (true) {
            $outcome instanceof TransportDeferred => new TransportDeferred($outcome->reason, $outcome->retryAt, $finalResponse, $lastRequestId),
            $outcome instanceof TransportBlocked => new TransportBlocked($outcome->reason, $lastRequestId),
            default => $outcome,
        };
    }

    private function run(OutboundRequestPlan $plan, ExecutionContext|OutboundExecutionContext $context, OutboundAttemptGate $gate, UrlPolicy $policy, TransferBudget $budget, HttpRequestPayload $payload, ?ProcessUuid &$lastRequestId, ?FinalHttpResponseEvidence &$finalResponse): TransportOutcome
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
        /** @var array<string, list<string>> $failedAddressesByOrigin */
        $failedAddressesByOrigin = [];
        // One transport attempt per adapter canary; redirects remain bounded, individually
        // authorized physical sends within that one logical read.
        $maxAttempts = $plan->adapterClaim?->canary === true || $plan->trafficClass === TrafficClass::Diagnostic ? 1 : $plan->maxAttempts();
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            while (true) {
                if ($plan->sendLimit !== null && $sends >= $plan->sendLimit) {
                    return new TransportBlocked(NetworkBlockedReason::PolicyReview);
                }
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
                $originKey = $url->origin->scheme.'://'.$url->origin->host.':'.$url->origin->port;
                $failedAddresses = $failedAddressesByOrigin[$originKey] ?? [];
                $selectedAddress = $resolved->address;
                foreach ($resolved->addresses as $candidateAddress) {
                    if (! in_array($candidateAddress, $failedAddresses, true)) {
                        $selectedAddress = $candidateAddress;
                        break;
                    }
                }
                if (in_array($selectedAddress, $failedAddresses, true)) {
                    $selectedAddress = $resolved->addresses[($attempt - 1) % count($resolved->addresses)];
                }
                $resolved = $resolved->withAddress($selectedAddress);
                $context->tick();
                if (! $context->canStart(Duration::seconds($plan->profile->totalSeconds))) {
                    return $this->deferred(NetworkDeferralReason::SliceExhausted, 1);
                }
                $physical = new OutboundRequestPlan(
                    $sends === 0 ? $plan->physicalRequestId : new ProcessUuid((string) Str::uuid()),
                    $targetId, $plan->method, $url, $plan->profile, $plan->trafficClass, $plan->idempotency, $plan->bodyHash, $plan->sendLimit, $plan->adapterClaim,
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
                $lastRequestId = $physical->physicalRequestId;
                $finalResponse = null;
                try {
                    $result = $this->transport->sendOnce($authorized, $resolved, $payload, $context, $budget);
                } catch (TransportInterrupted $exit) {
                    $gate->record($authorized, $exit->result);
                    throw $exit->interruption;
                }
                $transferredOwnership = false;
                try {
                    $quota = $gate->record($authorized, $result);
                    $finalResponse = $result->failure === null && $result->response !== null
                        && $result->response->status >= 400 && $result->response->body instanceof DecodedTextBody
                        ? new FinalHttpResponseEvidence($physical->physicalRequestId, $result->response->status, $result->response->body)
                        : null;
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
                        if (in_array($result->failure, [TransportFailure::Connect, TransportFailure::Timeout], true)
                            && ! in_array($resolved->address, $failedAddressesByOrigin[$originKey] ?? [], true)) {
                            $failedAddressesByOrigin[$originKey][] = $resolved->address;
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
                        if ($hops >= $plan->profile->redirects || ! in_array($plan->method, ['GET', 'HEAD'], true)) {
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
                            } catch (\InvalidArgumentException) {
                                return new TransportBlocked(NetworkBlockedReason::UrlPolicyDenied);
                            } catch (\RuntimeException) {
                                return $this->deferred(NetworkDeferralReason::DependencyUnavailable, $this->retryPolicy->dependencySeconds);
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
                    $transferredOwnership = true;

                    return new TransportSucceeded($response, $physical->physicalRequestId);
                } finally {
                    // A completed spool belongs to the caller only when returned via TransportSucceeded.
                    if (! $transferredOwnership && $result->response?->body instanceof BinaryBodyArtifact) {
                        $artifact = $result->response->body->artifact;
                        try {
                            if (! Storage::disk($artifact->disk)->delete($artifact->key)) {
                                Log::warning('Outbound binary artifact delete returned false after attempt.', [
                                    'physical_request_id' => $physical->physicalRequestId->value,
                                    'disk' => $artifact->disk,
                                    'artifact_key' => $artifact->key,
                                ]);
                            }
                        } catch (\Throwable $cleanupError) {
                            Log::warning('Outbound binary artifact delete failed after attempt.', [
                                'physical_request_id' => $physical->physicalRequestId->value,
                                'disk' => $artifact->disk,
                                'artifact_key' => $artifact->key,
                                'error_class' => $cleanupError::class,
                            ]);
                        }
                    }
                }
            }
            if ($attempt < $maxAttempts && ! $preferUntried) {
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
