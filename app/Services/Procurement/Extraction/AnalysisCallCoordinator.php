<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Llm\Requests\AuthorizedProviderOperation;
use App\Dto\Llm\Requests\ProviderOperationIds;
use App\Dto\Procurement\Ai\ExtractionProgress;
use App\Dto\Procurement\Ai\PageRange;
use App\Dto\Procurement\Ai\ScreeningProgress;
use App\Dto\Procurement\Process\AuthoritySnapshot;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\ExtractionWindowInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\ProviderRecoveryPlan;
use App\Dto\Procurement\Process\ScreeningInput;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\TaskPurpose;
use App\Enums\Procurement\Ai\ScreeningDisposition;
use App\Enums\Procurement\Process\ProviderOperationPurpose;
use App\Enums\Procurement\Process\ProviderRecoveryMode;
use App\Exceptions\Llm\OperationDenied;
use App\Exceptions\Procurement\Process\PublicationBlocked;
use App\Repositories\Llm\Request\RequestRepository;
use App\Repositories\Procurement\Ai\AnalysisPlanRepository;
use App\Repositories\Procurement\Ai\AnalysisRepository;
use App\Repositories\Procurement\Ai\AnalysisRequestRepository;
use App\Repositories\Procurement\Process\AuthorityRepository;
use App\Repositories\Procurement\Process\LockedPrefix;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Llm\Execution\ProviderWireRequest;
use App\Services\Llm\Execution\RequestLedger;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\SubjectLockResolver;
use App\Services\Procurement\Screening\ScreeningPolicy;
use Illuminate\Support\Str;

/** Cross-domain transaction owner. Financial authorization grants no HTTP capability. */
final readonly class AnalysisCallCoordinator
{
    public function __construct(
        private AuthorityRepository $authority,
        private SubjectLockResolver $subjects,
        private AnalysisRepository $analyses,
        private AnalysisRequestRepository $mapping,
        private RequestLedger $ledger,
        private ProcessTransaction $transactions,
        private RequestRepository $requests,
        private WorkStoreRepository $works,
        private AnalysisPlanRepository $plans,
    ) {}

    /** Save a proved safe retry directive without changing business state or granting another send. */
    public function pinKnownRetry(ExecutionLease $lease, AuthorizedProviderOperation $operation): ?string
    {
        $snapshot = $this->authority->readAuthoritySnapshot($lease->workId);
        if (! $snapshot instanceof AuthoritySnapshot) {
            throw new OperationDenied('consumer_authority_missing');
        }

        return $this->transactions->run(function () use ($lease, $operation, $snapshot): ?string {
            $prefix = $this->authority->lockPrefix($snapshot, skipLockedWork: false);
            if (! $prefix instanceof LockedPrefix || $prefix->work->string('state') !== 'running'
                || $prefix->work->int('generation') !== $lease->generation || $prefix->work->nullableString('owner_token') !== $lease->token->value
                || $prefix->work->bool('lease_expired') || ! $this->authority->exactMembership($snapshot, $prefix, 'running')
                || $this->subjects->inputInvalidated($snapshot->input, $snapshot->inputRevision, $prefix->subjects)) {
                throw new OperationDenied('consumer_send_authority_revoked');
            }
            $call = $this->requests->lock($this->requests->get($operation->physicalCallId));
            $plan = $this->works->lockedCheckpoint($prefix->work)?->providerRecoveryPlan;
            $mapping = $this->mapping->active($lease->workId);
            if ($call->authorization != $operation || ! $plan instanceof ProviderRecoveryPlan
                || $mapping?->physicalCallId !== $operation->physicalCallId
                || $plan->physicalCallId->value !== $operation->physicalCallId
                || ! in_array($call->outcome, ['not_sent', 'known_nonbillable_cache_miss', 'known_retryable_nonbillable'], true)) {
                throw new OperationDenied('unsafe_provider_retry');
            }
            $this->works->pinProviderOperationLocked($lease, new ProviderRecoveryPlan(ProviderRecoveryMode::KnownRetryEligible,
                $plan->logicalRequestId, $plan->physicalCallId, $plan->purpose, null, $plan->expectedInputHash, $plan->recipeHash, $plan->requestEpoch));

            return ! $prefix->controlsEnabled ? 'paused' : (! $prefix->handlerHealthy ? 'handler_hold' : null);
        });
    }

    public function authorize(ExecutionLease $lease, ProcessUuid $runId, ProviderOperationPlan $plan, string $periodId, ?ProviderWireRequest $wire = null): AuthorizedProviderOperation
    {
        $snapshot = $this->authority->readAuthoritySnapshot($lease->workId);
        if (! $snapshot instanceof AuthoritySnapshot) {
            throw new OperationDenied('consumer_authority_missing');
        }

        $ids = ProviderOperationIds::allocate();
        $linkId = new ProcessUuid((string) Str::uuid());

        return $this->transactions->run(function () use ($lease, $runId, $plan, $periodId, $snapshot, $ids, $linkId, $wire): AuthorizedProviderOperation {
            $prefix = $this->authority->lockPrefix($snapshot, skipLockedWork: false);
            if (! $prefix instanceof LockedPrefix || ! $prefix->controlsEnabled || ! $prefix->handlerHealthy || ! $prefix->intentActive
                || $prefix->work->string('state') !== 'running' || $prefix->work->int('generation') !== $lease->generation
                || $prefix->work->nullableString('owner_token') !== $lease->token->value || $prefix->work->bool('lease_expired')
                || $snapshot->inputHash != $lease->inputHash || $snapshot->handler->handlerVersion !== $lease->handlerVersion
                || ! $this->authority->exactMembership($snapshot, $prefix, 'running') || $prefix->group?->nullableString('attempt_id') !== $lease->attemptId->value
                || $this->subjects->inputInvalidated($snapshot->input, $snapshot->inputRevision, $prefix->subjects)) {
                throw new OperationDenied('consumer_send_authority_revoked');
            }
            $run = $this->analyses->getRun($runId);
            try {
                if ($run->source === null || ! $snapshot->input instanceof ScreeningInput) {
                    $this->plans->plans($run);
                } elseif ($run->source->representationHash != $run->representationHash) {
                    throw new \InvalidArgumentException('Source hash drift.');
                }
            } catch (PublicationBlocked|\InvalidArgumentException $invalid) {
                throw new OperationDenied('analysis_source_plan_invalid', previous: $invalid);
            }
            if ($snapshot->input instanceof ScreeningInput) {
                $screeningRun = $this->analyses->forScreening($snapshot->input);
                $progress = $this->works->lockedCheckpoint($prefix->work)?->stageProgress;
                $progress = $progress instanceof ScreeningProgress ? $progress : null;
                $discovery = $plan->generation?->purpose === TaskPurpose::Discovery;
                $range = $progress?->discovery?->pending[0] ?? ($run->source === null ? null : new PageRange(0, $run->source->pageCount));
                $expectedHash = $discovery && $range !== null ? $run->discoveryInputHash($range)->hex : $run->screeningInputHash()->hex;
                $expectedOrdinal = $discovery ? ($progress?->discovery?->reasks[$expectedHash] ?? 0) : ($progress->reaskOrdinal ?? 0);
                if ($screeningRun != $run || ($discovery && ($run->source === null || $progress?->result === null
                    || $progress->discovery?->pending === [] || (new ScreeningPolicy)->decide($progress->result, $run->recipe->screening)->disposition !== ScreeningDisposition::Extract))
                    || ($plan->identity->purpose === OperationPurpose::Generate && ($plan->identity->inputHash !== $expectedHash
                        || $plan->identity->semanticReaskOrdinal !== $expectedOrdinal
                        || (! $discovery && $progress?->result !== null) || $progress?->blocked !== null))) {
                    throw new OperationDenied('analysis_screening_drift');
                }
            }
            if ($plan->generation !== null && $snapshot->input instanceof ScreeningInput
                && ! in_array($plan->generation->purpose, [TaskPurpose::Screening, TaskPurpose::Discovery], true)) {
                throw new OperationDenied('analysis_task_stage_mismatch');
            }
            if ($snapshot->input instanceof ExtractionWindowInput
                && $plan->identity->purpose === OperationPurpose::Generate) {
                $window = $this->analyses->windowFor($snapshot->input);
                $progress = $this->works->lockedCheckpoint($prefix->work)?->stageProgress;
                if ($progress !== null && ! $progress instanceof ExtractionProgress) {
                    throw new OperationDenied('analysis_progress_codec_drift');
                }
                $range = $progress?->checkpoint?->pending[0] ?? $window->range;
                $ordinal = $progress?->reasks[$window->subrangeKey($range)->hex] ?? 0;
                if ($plan->identity->inputHash !== $window->subrangeInputHash($range)->hex
                    || $plan->identity->semanticReaskOrdinal !== $ordinal || $progress?->blocked !== null
                    || $progress?->checkpoint?->pending === []) {
                    throw new OperationDenied('analysis_subrange_drift');
                }
            }
            if ($run->recipe->hash->hex !== $plan->identity->recipeHash || $run->recipe->llm != $plan->context
                || ($plan->generation !== null && ! $run->recipe->tasks->matches($plan->generation))) {
                throw new OperationDenied('analysis_recipe_drift');
            }
            if ($plan->identity->replayIntentId !== $runId->value && ! $this->requests->approvedReplay($plan->identity)) {
                throw new OperationDenied('replay_intent_not_authorized');
            }
            $operation = $this->ledger->authorize($plan, $lease->attemptId->value, $lease->generation, $periodId, $ids, $wire);
            $prior = $this->mapping->active($lease->workId);
            if ($prior !== null && $prior->physicalCallId !== $operation->physicalCallId) {
                $old = $this->requests->get($prior->physicalCallId);
                if ($old->authorization->logicalRequestId !== $operation->logicalRequestId
                    || ! in_array($old->outcome, ['not_sent', 'known_retryable_nonbillable', 'known_nonbillable_cache_miss'], true)) {
                    throw new OperationDenied('prior_operation_not_consumed');
                }
                $this->mapping->consumeLocked($lease->workId, $old->authorization->physicalCallId);
                $this->works->consumeProviderRetryLocked($lease->workId, $prefix->work, new ProcessUuid($old->authorization->physicalCallId));
            }
            $this->mapping->linkLocked($lease, $runId, $operation, $linkId);
            $this->works->pinProviderOperationLocked($lease, new ProviderRecoveryPlan(
                ProviderRecoveryMode::BlockedUnknown,
                new ProcessUuid($operation->logicalRequestId), new ProcessUuid($operation->physicalCallId),
                match ($plan->identity->purpose) {
                    OperationPurpose::Generate => ProviderOperationPurpose::Generation,
                    OperationPurpose::FileUpload => ProviderOperationPurpose::File,
                    OperationPurpose::Lookup => ProviderOperationPurpose::Lookup,
                    default => ProviderOperationPurpose::Cache,
                }, null, $lease->inputHash, $run->recipe->hash, $operation->requestEpoch));

            return $operation;
        });
    }
}
