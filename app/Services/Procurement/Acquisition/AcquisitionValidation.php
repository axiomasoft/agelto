<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition;

use App\Dto\Procurement\Acquisition\FetchBlocked;
use App\Dto\Procurement\Acquisition\FetchDeferred;
use App\Dto\Procurement\Acquisition\ListingResult;
use App\Dto\Procurement\Acquisition\ValidatedAcquisitionResult;
use App\Dto\Procurement\Network\AdapterContractRef;
use App\Dto\Procurement\Network\AdapterInspection;
use App\Dto\Procurement\Network\AdapterValidationClaim;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Network\OutboundRequestPlan;
use App\Dto\Procurement\Network\TransferBudget;
use App\Dto\Procurement\Network\TransportBlocked;
use App\Dto\Procurement\Network\TransportDeferred;
use App\Dto\Procurement\Process\ControlSnapshot;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\RetryAt;
use App\Dto\Procurement\Process\StageBlocked;
use App\Dto\Procurement\Process\StageDeferred;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Enums\Procurement\Network\AdapterVerdict;
use App\Enums\Procurement\Network\NetworkDeferralReason;
use App\Enums\Procurement\Network\OutboundOperationKind;
use App\Enums\Procurement\Platform\Completeness;
use App\Enums\Procurement\Process\BlockedReason;
use App\Enums\Procurement\Process\DeferralReason;
use App\Exceptions\Procurement\Process\ExecutionInterrupted;
use App\Repositories\Procurement\Network\AdapterHealthRepository;
use App\Repositories\Procurement\Network\OutboundOperationRepository;
use App\Services\Procurement\Network\NetworkOwnerFence;
use App\Services\Procurement\Network\UrlPolicy;
use App\Services\Procurement\Platform\Contracts\PlatformOutbound;
use App\Services\Procurement\Platform\FetchOutcomeBridge;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Proxyma\CallerGate;
use App\Services\Procurement\Proxyma\CallerOutbound;
use App\Services\Procurement\Proxyma\CallerSendFacts;
use App\Services\Procurement\Proxyma\ProfileBindings;
use App\Services\Procurement\Proxyma\ProfileResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

final readonly class AcquisitionValidation
{
    public function __construct(private AdapterHealthRepository $adapters, private OutboundOperationRepository $operations,
        private ProcessTransaction $tx, private ProfileResolver $routes, private CallerOutbound $executor,
        private FetchOutcomeBridge $bridge, private AcquisitionArtifacts $artifacts) {}

    /** @param \Closure(PlatformOutbound, AcquisitionAssessment): StageOutcome $call */
    public function run(AdapterContractRef $contract, int $platformId, ProfileBindings $routes, OutboundRequestPlan $first,
        UrlPolicy $urls, ExecutionContext $work, \Closure $call): StageOutcome
    {
        if (! CallerSendFacts::active()) {
            return new StageBlocked(BlockedReason::HandlerHold);
        }
        $health = $this->adapters->find($contract);
        if ($health === null || $health->string('state') === 'held') {
            return new StageBlocked(BlockedReason::HandlerHold);
        }
        $work->tick();
        $selector = $this->routes->outboundRoutes($routes);
        $selection = CallerSendFacts::needsProbe($routes, $first->targetId->value)
            ? new TransportDeferred(NetworkDeferralReason::TargetCooldown, new RetryAt(new \DateTimeImmutable('+1 second'))) : null;
        $probe = null;
        $owner = $work;
        if ($health->string('state') !== 'healthy' || $selection instanceof TransportDeferred) {
            $seconds = min(240, (int) floor($work->remainingSlice()->milliseconds / 1000) - 15);
            if ($seconds < 1) {
                return new StageDeferred(DeferralReason::SliceExhausted, new RetryAt(new \DateTimeImmutable('+1 second')));
            }
            $authority = app(NetworkOwnerFence::class)->snapshot($work->lease())
                ?? throw new \LogicException('Missing acquisition authority.');
            $controls = array_values(array_unique([...array_map(fn (ControlSnapshot $c): string => $c->scopeKey, $authority->controls), 'monitoring']));
            $token = new ProcessUuid((string) Str::uuid());
            $probe = $this->tx->run(fn () => $this->operations->open(new ProcessUuid((string) Str::uuid()), $token,
                OutboundOperationKind::Probe, $platformId, 'acquisition.contract_canary', $contract->fingerprint(),
                $controls, $seconds, max($seconds, 240), probeToken: $token, parentAttemptId: $work->lease()->attemptId));
            $owner = new OutboundExecutionContext($probe,
                fn ($handle) => $this->tx->run(fn () => $this->operations->renew($handle, $seconds)), $work->tick(...), $seconds, 15);
            $selector = $routes;
        }
        try {
            $claim = $this->tx->run(fn () => $this->adapters->admit($contract, $owner));
            if (! $claim instanceof AdapterValidationClaim) {
                if ($probe === null && $claim instanceof TransportBlocked
                    && in_array($this->adapters->find($contract)?->string('state'), ['validating', 'suspect'], true)) {
                    return new StageDeferred(DeferralReason::CapacityWait, new RetryAt(new \DateTimeImmutable('+1 second')));
                }

                return $this->bridge->failure($claim instanceof TransportDeferred
                    ? new FetchDeferred($claim->reason, $claim->retryAt) : new FetchBlocked($claim->reason));
            }
            $owner->bindAdapter($claim);
            $gate = app()->makeWith(CallerGate::class, ['routes' => $selector]);
            $outbound = new AcquisitionPlatformOutbound($this->executor, $gate, $urls,
                new TransferBudget(134217728, 134217728), $work, $owner, $this->artifacts);
            $assessment = new AcquisitionAssessment($outbound->rejection(...));
            try {
                $result = $call($outbound, $assessment);
            } catch (\Throwable $failure) {
                if (! $failure instanceof ExecutionInterrupted && ! $failure instanceof QueryException
                    && $owner->successfulRequestIds() !== []) {
                    $inspection = new AdapterInspection($failure, AdapterVerdict::HandlerFault, 'acquisition.handler_fault');
                    $this->tx->run(fn () => $this->adapters->observe($owner, $work->lease()->inputHash, $inspection));
                }
                throw $failure;
            }
            if ($owner->successfulRequestIds() === []) {
                if ($result instanceof StageSucceeded) {
                    throw new \LogicException('Acquisition success lacks physical validation evidence.');
                }

                return $result;
            }
            $inspection = $assessment->inspection($result, $contract->fingerprint());
            if ($result instanceof StageSucceeded && $result->result instanceof ListingResult
                && ! in_array($result->result->page->assessment->completeness, [Completeness::Complete, Completeness::CompleteEmpty], true)) {
                $inspection = new AdapterInspection($result, AdapterVerdict::Partial, 'acquisition.incomplete_listing');
            }
            $receipt = $this->tx->run(fn () => $this->adapters->observe($owner, $work->lease()->inputHash, $inspection));
            if ($receipt->stale) {
                return new StageBlocked(BlockedReason::HandlerHold);
            }

            return $result instanceof StageSucceeded ? new StageSucceeded(new ValidatedAcquisitionResult($result->result, $receipt)) : $result;
        } finally {
            $this->tx->run(fn () => $this->adapters->release($owner));
            if ($probe !== null) {
                $this->tx->run(fn () => $this->operations->close($probe));
            }
        }
    }
}
