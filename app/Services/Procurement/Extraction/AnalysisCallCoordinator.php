<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Llm\Requests\AuthorizedProviderOperation;
use App\Dto\Llm\Requests\ProviderOperationIds;
use App\Dto\Procurement\Process\AuthoritySnapshot;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Exceptions\Llm\OperationDenied;
use App\Repositories\Llm\Request\RequestRepository;
use App\Repositories\Procurement\Ai\AnalysisRepository;
use App\Repositories\Procurement\Ai\AnalysisRequestRepository;
use App\Repositories\Procurement\Process\AuthorityRepository;
use App\Repositories\Procurement\Process\LockedPrefix;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Llm\Execution\RequestLedger;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\SubjectLockResolver;
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
    ) {}

    public function authorize(ExecutionLease $lease, ProcessUuid $runId, ProviderOperationPlan $plan, string $periodId): AuthorizedProviderOperation
    {
        $snapshot = $this->authority->readAuthoritySnapshot($lease->workId);
        if (! $snapshot instanceof AuthoritySnapshot) {
            throw new OperationDenied('consumer_authority_missing');
        }

        $ids = ProviderOperationIds::allocate();
        $linkId = new ProcessUuid((string) Str::uuid());

        return $this->transactions->run(function () use ($lease, $runId, $plan, $periodId, $snapshot, $ids, $linkId): AuthorizedProviderOperation {
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
            if ($run->recipe->hash->hex !== $plan->identity->recipeHash || $run->recipe->llm != $plan->context) {
                throw new OperationDenied('analysis_recipe_drift');
            }
            if ($plan->identity->replayIntentId !== $runId->value && ! $this->requests->approvedReplay($plan->identity)) {
                throw new OperationDenied('replay_intent_not_authorized');
            }
            $operation = $this->ledger->authorize($plan, $lease->attemptId->value, $lease->generation, $periodId, $ids);
            $prior = $this->mapping->active($lease->workId);
            if ($prior !== null && $prior->string('physical_call_id') !== $operation->physicalCallId) {
                $old = $this->requests->get($prior->string('physical_call_id'));
                if ($old->authorization->logicalRequestId !== $operation->logicalRequestId
                    || ! in_array($old->outcome, ['not_sent', 'known_retryable_nonbillable', 'known_nonbillable_cache_miss'], true)) {
                    throw new OperationDenied('prior_operation_not_consumed');
                }
                $this->mapping->consumeLocked($lease->workId, $old->authorization->physicalCallId);
                $this->works->consumeProviderRetryLocked($lease->workId, $prefix->work, new ProcessUuid($old->authorization->physicalCallId));
            }
            $this->mapping->linkLocked($lease, $runId, $operation, $linkId);

            return $operation;
        });
    }
}
