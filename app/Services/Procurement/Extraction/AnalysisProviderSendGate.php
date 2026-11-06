<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Llm\Requests\AuthorizedProviderOperation;
use App\Dto\Procurement\Network\DecodedTextBody;
use App\Dto\Procurement\Network\HttpAttemptResult;
use App\Dto\Procurement\Network\NetworkPermitWait;
use App\Dto\Procurement\Network\NetworkSendClaim;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\ProviderKey;
use App\Dto\Procurement\Network\ReservedProviderNetwork;
use App\Dto\Procurement\Network\ResponseEnvelope;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Platform\RequestProfile;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\Duration;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Llm\ProviderSendDenial;
use App\Enums\Llm\ProviderTransportFailure;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\NetworkBlockedReason;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Network\TrafficClass;
use App\Enums\Procurement\Network\TransportFailure;
use App\Exceptions\Llm\OperationDenied;
use App\Exceptions\Procurement\Process\ExecutionInterrupted;
use App\Exceptions\Procurement\Process\SliceExhausted;
use App\Repositories\Llm\Request\FinancialReservationRepository;
use App\Repositories\Llm\Request\RequestRepository;
use App\Repositories\Procurement\Ai\AnalysisRepository;
use App\Repositories\Procurement\Ai\AnalysisRequestRepository;
use App\Repositories\Procurement\Network\NetworkPermitRepository;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Llm\Execution\ProviderSendGate;
use App\Services\Llm\Execution\ProviderTransportOutcome;
use App\Services\Llm\Execution\ProviderWireRequest;
use App\Services\Llm\Execution\SendClaimResult;
use App\Services\Procurement\Network\Contracts\OutboundRouteSelector;
use App\Services\Procurement\Network\HttpTransport;
use App\Services\Procurement\Network\NetworkOwnerFence;
use App\Services\Procurement\Network\OutboundExecutor;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Network\UrlPolicy;
use App\Services\Procurement\Process\ProcessTransaction;
use Illuminate\Support\Facades\DB;

/** Per-execution owning port. Not a singleton and not activated in paid stage handlers. */
final readonly class AnalysisProviderSendGate implements ProviderSendGate
{
    public function __construct(
        private ExecutionContext $context, private ProcessUuid $runId, private ProcessUuid $target,
        private RequestProfile $profile, private UrlPolicy $urlPolicy, private OutboundRouteSelector $routes,
        private string $apiKey, private OutboundExecutor $outbound, private NetworkOwnerFence $owners,
        private NetworkPermitRepository $permits, private RequestRepository $requests,
        private FinancialReservationRepository $finance, private AnalysisRepository $analyses,
        private AnalysisRequestRepository $mapping, private ProcessTransaction $transactions,
        private HttpTransport $transports,
        private OutboundPolicy $policy,
    ) {}

    public function claimSend(AuthorizedProviderOperation $operation, ProviderWireRequest $wireRequest): SendClaimResult
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Provider gate cannot wait inside a transaction.');
        }
        $network = null;
        $waitDeadlineNs = hrtime(true) + $this->policy->maxInlineWaitSeconds * 1_000_000_000;
        try {
            $this->revalidate($operation, $wireRequest);
            $this->context->tick();
            if (! $this->context->canStart(Duration::seconds($this->profile->totalSeconds))) {
                throw new OperationDenied('full_timeout_does_not_fit');
            }
            $provider = new ProviderKey($wireRequest->operation->context->provider);
            $plan = new OutboundRequestPlan(new ProcessUuid($operation->physicalCallId), $this->target, $wireRequest->method,
                new SafeUrl($wireRequest->endpoint), $this->profile, TrafficClass::Provider,
                $wireRequest->method === 'GET' ? IdempotencyClass::ReadOnly : IdempotencyClass::AmbiguousWrite,
                $wireRequest->method === 'GET' ? null : new ContentHash($wireRequest->bodyHash), 1);
            $network = $this->outbound->reserveProviderSend($plan, $this->context, $this->routes, $this->urlPolicy, $provider,
                fn () => $this->revalidate($operation, $wireRequest));
            if (! $network instanceof ReservedProviderNetwork) {
                $this->revoke($operation);

                return $this->networkDenial($network);
            }
            do {
                $this->context->tick();
                if (! $this->context->canStart(Duration::seconds($this->profile->totalSeconds))) {
                    throw new OperationDenied('full_timeout_does_not_fit');
                }
                $snapshot = $this->owners->snapshot($this->context->lease(), $provider);
                $claimBeganNs = hrtime(true);
                try {
                    $claim = $this->transactions->run(function () use ($operation, $wireRequest, $network, $provider, $snapshot): NetworkSendClaim {
                        $state = $this->owners->lock($this->context->lease(), $snapshot, $provider);
                        if ($state instanceof TransportBlocked) {
                            throw new JointProviderSendRejected($this->networkDenial($state));
                        }
                        $this->assertConsumer($operation, $wireRequest);
                        if (! $this->requests->claimFinancialLocked($operation, $wireRequest->fingerprint)) {
                            throw new JointProviderSendRejected(new SendClaimResult(null, ProviderSendDenial::AlreadyClaimed));
                        }
                        $claim = $this->permits->claimSendLocked($network->permit, $this->context, $state);
                        if (! $claim instanceof NetworkSendClaim || ! $claim->claimed) {
                            throw new JointProviderSendRejected($claim instanceof NetworkPermitWait ? $claim
                                : ($claim instanceof NetworkSendClaim ? new SendClaimResult(null, ProviderSendDenial::Revoked) : $this->networkDenial($claim)));
                        }

                        return $claim;
                    });
                } catch (JointProviderSendRejected $rejected) {
                    $claim = $rejected->result;
                    if ($claim instanceof NetworkPermitWait) {
                        if (hrtime(true) >= $waitDeadlineNs) {
                            throw new OperationDenied('network_wait_exhausted');
                        }
                        $this->revalidate($operation, $wireRequest);
                        $delay = LedgerRow::one(
                            'SELECT GREATEST(0, EXTRACT(EPOCH FROM (?::timestamptz - clock_timestamp())) * 1000000)::bigint AS delay',
                            [$claim->retryAt->value->format('Y-m-d H:i:s.uP')],
                        )?->int('delay') ?? 0;
                        if ($delay > 0) {
                            usleep(min(1_000_000, max(10_000, $delay)));
                        }
                    }
                }
            } while ($claim instanceof NetworkPermitWait);
            if ($claim instanceof SendClaimResult) {
                $this->permits->abandon($network->permit->ticket, $this->context);
                if ($claim->denial !== ProviderSendDenial::AlreadyClaimed) {
                    $this->revoke($operation);
                }

                return $claim;
            }
            // DB duration -> conservative monotonic deadline sampled BEFORE the transaction.
            // PHP wall-clock skew must never extend the authority granted by PostgreSQL.
            $coverageNs = ($claim->expiresAt->getTimestamp() - $claim->authorizedAt->getTimestamp()) * 1_000_000_000
                + ((int) $claim->expiresAt->format('u') - (int) $claim->authorizedAt->format('u')) * 1000;
            $transport = $this->transports->prepareProvider($wireRequest, $operation->physicalCallId, $network, $this->context, $this->apiKey,
                $claimBeganNs + max(0, $coverageNs - ($this->profile->totalSeconds + 15) * 1_000_000_000));

            return new SendClaimResult($transport, null);
        } catch (OperationDenied $denied) {
            $this->abandon($network instanceof ReservedProviderNetwork ? $network : null);
            if ($denied->getMessage() !== 'already_claimed') {
                $this->revoke($operation);
            }

            return new SendClaimResult(null, match ($denied->getMessage()) {
                'already_claimed' => ProviderSendDenial::AlreadyClaimed,
                'sealed_wire_conflict' => ProviderSendDenial::WireMismatch,
                'full_timeout_does_not_fit' => ProviderSendDenial::SliceExhausted,
                'network_wait_exhausted' => ProviderSendDenial::RateLimit,
                'account_unknown_hold', 'account_exposure_hold' => ProviderSendDenial::PolicyReview,
                'authorization_revoked', 'financial_reservation_not_active' => ProviderSendDenial::Revoked,
                default => ProviderSendDenial::Configuration,
            });
        } catch (SliceExhausted) {
            $this->abandon($network);
            $this->revoke($operation);

            return new SendClaimResult(null, ProviderSendDenial::SliceExhausted);
        } catch (ExecutionInterrupted $interrupted) {
            $this->abandon($network);
            $this->revoke($operation);

            throw $interrupted;
        }
    }

    private function networkDenial(TransportDeferred|TransportBlocked $outcome): SendClaimResult
    {
        $reason = match ($outcome->reason) {
            NetworkDeferralReason::NoRoute => ProviderSendDenial::Network,
            NetworkDeferralReason::RateLimit, NetworkDeferralReason::StatusRefreshBudget => ProviderSendDenial::RateLimit,
            NetworkDeferralReason::CapacityWait => ProviderSendDenial::CapacityWait,
            NetworkDeferralReason::TargetCooldown => ProviderSendDenial::TargetCooldown,
            NetworkDeferralReason::SliceExhausted => ProviderSendDenial::SliceExhausted,
            NetworkDeferralReason::DependencyUnavailable, NetworkDeferralReason::AttemptsExhausted => ProviderSendDenial::Dependency,
            NetworkBlockedReason::Paused => ProviderSendDenial::Paused,
            NetworkBlockedReason::OwnerLost => ProviderSendDenial::Revoked,
            NetworkBlockedReason::AdapterHeld, NetworkBlockedReason::TargetRetired, NetworkBlockedReason::PolicyReview => ProviderSendDenial::PolicyReview,
            NetworkBlockedReason::CredentialHold, NetworkBlockedReason::ConfigurationHold, NetworkBlockedReason::UrlPolicyDenied => ProviderSendDenial::Configuration,
        };

        return new SendClaimResult(null, $reason, $outcome instanceof TransportDeferred ? $outcome->retryAt->value : null);
    }

    private function abandon(?ReservedProviderNetwork $network): void
    {
        if ($network !== null) {
            $this->permits->abandon($network->permit->ticket, $this->context);
        }
    }

    private function assertConsumer(AuthorizedProviderOperation $operation, ProviderWireRequest $wire): void
    {
        $wire->capabilities?->assertContext($wire->operation->context);
        $lease = $this->context->lease();
        $link = $this->mapping->active($lease->workId);
        $run = $this->analyses->getRun($this->runId);
        if ($operation->ownerAttemptId !== $lease->attemptId->value || $operation->ownerWorkGeneration !== $lease->generation
            || ! $this->mapping->current($operation->physicalCallId)
            || $link?->physicalCallId !== $operation->physicalCallId || $link->analysisRunId->value !== $this->runId->value
            || $link->expectedWorkInputHash->hex !== $lease->inputHash->hex || $link->recipeHash->hex !== $wire->operation->identity->recipeHash
            || $run->recipe->hash->hex !== $wire->operation->identity->recipeHash || $run->recipe->llm != $wire->operation->context
            || ($wire->operation->generation !== null && ! $run->recipe->tasks->matches($wire->operation->generation))) {
            throw new OperationDenied('consumer_operation_drift');
        }
    }

    private function revalidate(AuthorizedProviderOperation $operation, ProviderWireRequest $wire): void
    {
        $this->assertConsumer($operation, $wire);
        $this->transactions->run(function () use ($operation, $wire): void {
            $call = $this->requests->lock($this->requests->get($operation->physicalCallId));
            if ($call->authorization != $operation || $call->wireFingerprint !== $wire->fingerprint) {
                throw new OperationDenied('sealed_wire_conflict');
            }
            if ($call->claimed) {
                throw new OperationDenied('already_claimed');
            }
            if ($call->requestState !== 'sending') {
                throw new OperationDenied('authorization_revoked');
            }
            $this->finance->assertSendEligible($this->finance->lockAccount($call->context->account, $call->currency), $call->upperBound, alreadyReserved: true);
        });
    }

    private function revoke(AuthorizedProviderOperation $operation): void
    {
        $this->transactions->run(function () use ($operation): void {
            $call = $this->requests->get($operation->physicalCallId);
            if ($call->authorization == $operation && ! $call->claimed) {
                $this->requests->interruptLocked($call);
            }
        });
    }

    public function recordTransportOutcome(string $physicalCallId, ProviderTransportOutcome $outcome): void
    {
        if ($physicalCallId !== $outcome->physicalCallId
            || $this->mapping->physical($physicalCallId)->workId->value->value !== $this->context->lease()->workId->value->value) {
            throw new \InvalidArgumentException('Transport outcome belongs to a different physical operation.');
        }
        $bytes = strlen($outcome->body() ?? '');
        $response = $outcome->status === null ? null : new ResponseEnvelope($outcome->status, $outcome->headers, new DecodedTextBody('', 'UTF-8'), $bytes, $bytes);
        $failure = match ($outcome->failure) {
            null => null,
            ProviderTransportFailure::Connection => TransportFailure::Connect,
            ProviderTransportFailure::ProxyConnect => TransportFailure::ProxyConnect,
            ProviderTransportFailure::Tls => TransportFailure::Tls,
            ProviderTransportFailure::Timeout => TransportFailure::Timeout,
            ProviderTransportFailure::Truncated => TransportFailure::Truncated,
            ProviderTransportFailure::Interrupted, ProviderTransportFailure::Deadline => TransportFailure::Cancelled,
        };
        $this->permits->record(new ProcessUuid($physicalCallId), new HttpAttemptResult($response, $failure, $bytes, $bytes));
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new \LogicException('Runtime provider owner cannot be serialized.');
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['owner' => $this->context->lease()->attemptId->value];
    }
}
