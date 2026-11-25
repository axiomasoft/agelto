<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Llm\Requests\AuthorizedProviderOperation;
use App\Dto\Procurement\Network\DecodedTextBody;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\ResponseEnvelope;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Network\TransportMetrics;
use App\Dto\Procurement\Platform\RequestProfile;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\Duration;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Llm\ProviderSendDenial;
use App\Enums\Llm\ProviderTransportFailure;
use App\Enums\Procurement\Network\IdempotencyClass;
use App\Enums\Procurement\Network\TrafficClass;
use App\Enums\Procurement\Network\TransportFailure;
use App\Exceptions\Llm\OperationDenied;
use App\Exceptions\Procurement\Process\ExecutionInterrupted;
use App\Exceptions\Procurement\Process\SliceExhausted;
use App\Models\Procurement\Proxyma\CutoverJournal;
use App\Models\Procurement\Proxyma\NetworkAuthority;
use App\Repositories\Llm\Request\FinancialReservationRepository;
use App\Repositories\Llm\Request\RequestRepository;
use App\Repositories\Procurement\Ai\AnalysisRepository;
use App\Repositories\Procurement\Ai\AnalysisRequestRepository;
use App\Services\Llm\Execution\PreparedProviderTransport;
use App\Services\Llm\Execution\ProviderSendGate;
use App\Services\Llm\Execution\ProviderTransportOutcome;
use App\Services\Llm\Execution\ProviderWireRequest;
use App\Services\Llm\Execution\SendClaimResult;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Network\UrlPolicy;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Proxyma\ProcessOwnerAuthority;
use App\Services\Procurement\Proxyma\ProviderNetwork;
use App\Services\Procurement\Proxyma\RuntimeCaller;
use App\Services\Procurement\Proxyma\RuntimePhysicalTransport;
use Illuminate\Support\Facades\DB;
use Proxyma\Application\Admission\AuthorityLocker;
use Proxyma\Application\Admission\ClaimSend;
use Proxyma\Application\Admission\ReservationService;
use Proxyma\Application\Admission\TransactionCoordinator;
use Proxyma\Application\Authorization\AuthorizationDenied;
use Proxyma\Contracts\Completion;
use Proxyma\Contracts\PhysicalTransport;
use Proxyma\Domain\Admission\AdmissionRejected;
use Proxyma\Domain\Admission\AuthorityLockTier;
use Proxyma\Domain\Admission\ReservationDeferred;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\ConnectionScope;
use Proxyma\Infrastructure\Database\PackageSchema;

/** Per-execution owning port. Not a singleton and not activated in paid stage handlers. */
final readonly class AnalysisProviderSendGate implements ProviderSendGate
{
    public function __construct(
        private ExecutionContext $context, private ProcessUuid $runId, private ProcessUuid $target,
        private RequestProfile $profile, private UrlPolicy $urlPolicy,
        private string $apiKey,
        private RequestRepository $requests,
        private FinancialReservationRepository $finance, private AnalysisRepository $analyses,
        private AnalysisRequestRepository $mapping, private ProcessTransaction $transactions,
        private OutboundPolicy $policy,
    ) {}

    public function claimSend(AuthorizedProviderOperation $operation, ProviderWireRequest $wireRequest): SendClaimResult
    {
        try {
            return $this->claimSendChecked($operation, $wireRequest);
        } catch (OperationDenied $denied) {
            if ($denied->getMessage() !== 'already_claimed') {
                $this->revoke($operation);
            }

            return new SendClaimResult(null, match ($denied->getMessage()) {
                'already_claimed' => ProviderSendDenial::AlreadyClaimed,
                'sealed_wire_conflict' => ProviderSendDenial::WireMismatch,
                'full_timeout_does_not_fit' => ProviderSendDenial::SliceExhausted,
                'network_wait_exhausted' => ProviderSendDenial::RateLimit,
                'account_unknown_hold', 'account_exposure_hold' => ProviderSendDenial::PolicyReview,
                'authorization_revoked', 'financial_reservation_not_active', 'consumer_operation_drift' => ProviderSendDenial::Revoked,
                default => ProviderSendDenial::Configuration,
            });
        } catch (SliceExhausted) {
            $this->revoke($operation);

            return new SendClaimResult(null, ProviderSendDenial::SliceExhausted);
        } catch (ExecutionInterrupted $interrupted) {
            $this->revoke($operation);

            throw $interrupted;
        }
    }

    private function claimSendChecked(AuthorizedProviderOperation $operation, ProviderWireRequest $wireRequest): SendClaimResult
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('Provider gate cannot wait inside a transaction.');
        }
        $waitDeadlineNs = hrtime(true) + $this->policy->maxInlineWaitSeconds * 1_000_000_000;
        $this->revalidate($operation, $wireRequest);
        $this->context->tick();
        if (! $this->context->canStart(Duration::seconds($this->profile->totalSeconds))) {
            throw new OperationDenied('full_timeout_does_not_fit');
        }
        $plan = new OutboundRequestPlan(new ProcessUuid($operation->physicalCallId), $this->target, $wireRequest->method,
            new SafeUrl($wireRequest->endpoint), $this->profile, TrafficClass::Provider,
            $wireRequest->method === 'GET' ? IdempotencyClass::ReadOnly : IdempotencyClass::AmbiguousWrite,
            $wireRequest->method === 'GET' ? null : new ContentHash($wireRequest->bodyHash), 1);
        if (! $this->urlPolicy->allows($plan->url)
            || DB::table((new NetworkAuthority)->getTable())->where('id', 'network')->value('authority') !== 'proxyma') {
            $this->revoke($operation);

            return new SendClaimResult(null, ProviderSendDenial::Configuration);
        }

        return $this->claimPackage($operation, $wireRequest, $plan, $waitDeadlineNs);
    }

    private function claimPackage(AuthorizedProviderOperation $operation, ProviderWireRequest $wire,
        OutboundRequestPlan $plan, int $waitDeadlineNs): SendClaimResult
    {
        try {
            return app(RuntimeCaller::class)->run($plan, $this->context,
                fn (): SendClaimResult => $this->claimPackageAuthorized($operation, $wire, $plan, $waitDeadlineNs));
        } catch (AdmissionRejected $denied) {
            $this->revoke($operation);

            return new SendClaimResult(null, self::admissionDenial($denied->reason));
        }
    }

    private function claimPackageAuthorized(AuthorizedProviderOperation $operation, ProviderWireRequest $wire,
        OutboundRequestPlan $plan, int $waitDeadlineNs): SendClaimResult
    {
        // Resolve the scoped coordinator before BEGIN so its monotonic clock observes the outer transaction.
        $coordinator = app(TransactionCoordinator::class);
        $scope = app(ConnectionScope::class);
        $scope->assertSame(DB::connection());
        $locks = app(AuthorityLocker::class);
        $owners = app(ProcessOwnerAuthority::class);
        $prepared = null;
        $authorized = false;
        try {
            $claims = app(ClaimSend::class);
            $prepared = app(ProviderNetwork::class)->prepare($plan, $wire, $this->context, $this->apiKey,
                $waitDeadlineNs, fn () => $this->revalidate($operation, $wire));
            do {
                $this->context->tick();
                $claims->prepare($prepared->permit, $prepared->input, $prepared->wire);
                try {
                    $candidate = $this->transactions->run(fn () => $coordinator->locked(DB::connection(), function ($tx) use (
                        $operation, $wire, $prepared, $claims, $locks, $owners
                    ) {
                        $input = $prepared->input;
                        $state = $locks->owner($owners, $input->operation->owner, $input->ownerSnapshot, $tx);
                        $this->assertConsumer($operation, $wire);
                        $selector = DB::table((new NetworkAuthority)->getTable())->where('id', 'network')->first();
                        $marked = DB::table((new CutoverJournal)->getTable())->where('id', $selector?->cutover_id)
                            ->where('generation', $selector?->generation)->whereIn('phase', ['switched', 'accepted'])
                            ->update(['first_authorized_at' => DB::raw('COALESCE(first_authorized_at, clock_timestamp())')]);
                        if ($marked !== 1) {
                            throw new AdmissionRejected('cutover_journal_not_switched');
                        }
                        $locks->enter(AuthorityLockTier::Finance, $operation->physicalCallId, $tx);
                        if (! $this->requests->claimFinancialLocked($operation, $wire->fingerprint)) {
                            throw new JointProviderSendRejected(new SendClaimResult(null, ProviderSendDenial::AlreadyClaimed));
                        }

                        return $claims->claimSendLocked($prepared->permit, $input, $prepared->wire, $tx, $state);
                    }));
                    break;
                } catch (AdmissionRejected $denied) {
                    if ($denied->reason !== 'send_budget_unavailable' || hrtime(true) >= $waitDeadlineNs) {
                        throw $denied;
                    }
                    // Financial mutation has rolled back. Wait outside SQL, bounded by the original monotonic deadline.
                    $this->revalidate($operation, $wire);
                    usleep(100_000);
                }
            } while (true);
            $authorized = true;
            $capability = $claims->activate($candidate);

            return new SendClaimResult(new PreparedProviderTransport($capability, $wire, $operation->physicalCallId,
                $capability->latestStartMonotonicNs, new RuntimePhysicalTransport(app()->makeWith(PhysicalTransport::class, ['cancelled' => function (): bool {
                    try {
                        $this->context->tick();

                        return false;
                    } catch (ExecutionInterrupted) {
                        // Авторизованный POST остаётся unknown: отмена transfer не доказывает отсутствие remote effect.
                        return true;
                    }
                }]), app(RuntimeCaller::class), $plan, $this->context, allowPausedOwner: true), function (): bool {
                    $this->context->tick();

                    return $this->context->canStart(Duration::seconds($this->profile->totalSeconds));
                }), null);
        } catch (JointProviderSendRejected $denied) {
            return $denied->result instanceof SendClaimResult ? $denied->result : new SendClaimResult(null, ProviderSendDenial::RateLimit);
        } catch (ReservationDeferred $deferred) {
            $this->revoke($operation);

            return new SendClaimResult(null, ProviderSendDenial::RateLimit, $deferred->wait->retryAt->value);
        } catch (AdmissionRejected|AuthorizationDenied|\DomainException|\InvalidArgumentException $denied) {
            $this->revoke($operation);

            return new SendClaimResult(null, self::admissionDenial($denied instanceof AdmissionRejected ? $denied->reason : ''));
        } finally {
            if ($prepared !== null && ! $authorized && DB::table(PackageSchema::tables()['permits'])
                ->where('id', $prepared->permit->permitId->value)->whereNull('authorized_at')->where('state', 'reserved')->exists()) {
                app(ReservationService::class)->cancel(TenantKey::system(), $prepared->input->operation->operationId, $prepared->permit->permitId);
            }
        }
    }

    private static function admissionDenial(string $reason): ProviderSendDenial
    {
        return match ($reason) {
            'already_claimed' => ProviderSendDenial::AlreadyClaimed,
            'provider_route_unavailable', 'no_route' => ProviderSendDenial::Network,
            'owner_paused' => ProviderSendDenial::Paused,
            'capacity_unavailable' => ProviderSendDenial::CapacityWait,
            'target_cooldown' => ProviderSendDenial::TargetCooldown,
            'send_budget_unavailable' => ProviderSendDenial::RateLimit,
            'owner_lost', 'owner_deadline', 'owner_snapshot_stale' => ProviderSendDenial::Revoked,
            default => ProviderSendDenial::Configuration,
        };
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
            $lockedAccount = null;
            $call = $this->requests->lock(expected: $this->requests->get($operation->physicalCallId), lockedAccount: $lockedAccount);
            if ($call->authorization != $operation || $call->wireFingerprint !== $wire->fingerprint) {
                throw new OperationDenied('sealed_wire_conflict');
            }
            if ($call->claimed) {
                throw new OperationDenied('already_claimed');
            }
            if ($call->requestState !== 'sending') {
                throw new OperationDenied('authorization_revoked');
            }
            $this->finance->assertSendEligible(account: $lockedAccount, newExposure: $call->upperBound, alreadyReserved: true);
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
        $wireBytes = $outcome->wireBytes;
        $entityBytes = $outcome->entityBytes;
        $response = $outcome->status === null ? null : new ResponseEnvelope($outcome->status, $outcome->headers, new DecodedTextBody('', 'UTF-8'), $wireBytes, $entityBytes);
        $failure = match ($outcome->failure) {
            null => null,
            ProviderTransportFailure::Connection => TransportFailure::Connect,
            ProviderTransportFailure::ProxyConnect => TransportFailure::ProxyConnect,
            ProviderTransportFailure::Tls => TransportFailure::Tls,
            ProviderTransportFailure::Timeout => TransportFailure::Timeout,
            ProviderTransportFailure::Truncated => TransportFailure::Truncated,
            ProviderTransportFailure::Interrupted, ProviderTransportFailure::Deadline => TransportFailure::Cancelled,
        };
        $metrics = $outcome->stats === null ? null : TransportMetrics::fromCurlStats($outcome->stats);
        $package = PackageSchema::tables();
        // Durable correlation chooses completion authority even after owner generation is lost.
        if (DB::getSchemaBuilder()->hasTable($package['attempts'])
            && DB::table($package['attempts'])->where('physical_attempt_id', $physicalCallId)->exists()) {
            // Финансовый outcome намеренно грубее сетевого: ProxyAuth и partial head нельзя восстанавливать из него.
            $result = $outcome->networkResult ?? throw new \LogicException('Missing original package transport result.');
            app(RuntimeCaller::class)->recordReceipt(new Uuid($physicalCallId), $this->context,
                fn () => app(Completion::class)->record(new Uuid($physicalCallId), new Uuid($physicalCallId), $result));

            return;
        }
        throw new \LogicException('Missing package transport authorization; legacy history is read-only.');
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
