<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process\Work;

use App\Dto\Procurement\Process\Authority\AuthoritySnapshot;
use App\Dto\Procurement\Process\Execution\CompletionResult;
use App\Dto\Procurement\Process\Execution\ExecutionLease;
use App\Dto\Procurement\Process\Execution\HandlerFaultEvidence;
use App\Dto\Procurement\Process\Execution\StoreWorkData;
use App\Dto\Procurement\Process\Execution\WorkIdentity;
use App\Dto\Procurement\Process\Governance\TransitionData;
use App\Dto\Procurement\Process\Inputs\DetailInput;
use App\Dto\Procurement\Process\Inputs\DownloadInput;
use App\Dto\Procurement\Process\Inputs\WorkInput;
use App\Dto\Procurement\Process\Outcomes\FinalizationDecision;
use App\Dto\Procurement\Process\Outcomes\FinishLockedResult;
use App\Dto\Procurement\Process\Outcomes\StageBlocked;
use App\Dto\Procurement\Process\Outcomes\StageDeferred;
use App\Dto\Procurement\Process\Outcomes\StageFailedItem;
use App\Dto\Procurement\Process\Outcomes\StageOutcome;
use App\Dto\Procurement\Process\Outcomes\StagePartial;
use App\Dto\Procurement\Process\Outcomes\StageSucceeded;
use App\Dto\Procurement\Process\Outcomes\WorkCheckpointEnvelope;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Dto\Procurement\Process\Provider\ProviderRecoveryPlan;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Core\WorkState;
use App\Enums\Procurement\Process\Governance\ActorKind;
use App\Enums\Procurement\Process\Outcomes\BlockedReason;
use App\Enums\Procurement\Process\Outcomes\DeferralReason;
use App\Enums\Procurement\Process\Outcomes\PrefixFailure;
use App\Enums\Procurement\Process\Outcomes\ProviderRecoveryMode;
use App\Repositories\Procurement\Process\Authority\AuthorityRepository;
use App\Repositories\Procurement\Process\Authority\IncidentRepository;
use App\Repositories\Procurement\Process\Projection\TransitionRepository;
use App\Repositories\Procurement\Process\Support\CallerTransaction;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Repositories\Procurement\Process\Support\LockedPrefix;
use App\Services\Procurement\Process\Contracts\PublicationLocks;
use App\Services\Procurement\Process\Contracts\StagePublicationPreparation;
use App\Services\Procurement\Process\Contracts\StagePublicationRepository;
use App\Services\Procurement\Process\Execution\StageRegistry;
use App\Services\Procurement\Process\Execution\SubjectLockResolver;
use App\Services\Procurement\Process\Infrastructure\ProcessPolicy;
use Illuminate\Support\Facades\DB;

/**
 * Fenced finalize/defer/block/partial и handler fault внутри
 * уже открытой ProcessTransaction. Полный prefix control SHARE → handler SHARE → capacity UPDATE →
 * subjects UPDATE → work UPDATE; control/handler rows здесь никогда не UPDATE-ятся (no SHARE
 * upgrade). До domain writes: generation+token+running текущего lease, immutable input, subject
 * head/intent и exact group/attempt. lease_until > clock_timestamp() НЕ требуется: истёкший, но не
 * отозванный owner сериализуется row locks с recovery/cancel (late DB-only finalize). Чужой owner —
 * LostOwnership без единой записи. Pause/handler hold не отбрасывают уже полученный результат.
 * Result + checkpoint + successors/run links + work state + attempt + release всей group +
 * transition/projection + coalesced feeder wake — одна короткая tx; mandatory failure откатывает всё.
 */
final readonly class WorkFinalizationRepository
{
    /** Genuine item defect budget на work_key (DDL CHECK item_failures 0..3). */
    private const int ITEM_FAILURE_LIMIT = 3;

    /** Due первых двух item retry от DB clock: +30 s, затем +120 s. */
    private const int FIRST_ITEM_RETRY_SECONDS = 30;

    private const int SECOND_ITEM_RETRY_SECONDS = 120;

    /** Unexpected handler fault ниже budget: deferred(handler_fault) +60 s. */
    private const int HANDLER_FAULT_DELAY_SECONDS = 60;

    /** blocked(provider_result_unknown): отдельный reconciliation schedule +30 s, due_at NULL. */
    private const int PROVIDER_RECOVERY_SECONDS = 30;

    /** Deferred jitter: неотрицательный, до 10% оставшегося до RetryAt интервала. */
    private const int JITTER_PERMILLE = 100;

    public function __construct(
        private AuthorityRepository $authority,
        private WorkStoreRepository $works,
        private TransitionRepository $transitions,
        private OutboxRepository $outbox,
        private IncidentRepository $incidents,
        private SubjectLockResolver $resolver,
        private StageRegistry $registry,
        private ProcessPolicy $policy,
        private IntentRepository $intents,
    ) {}

    public function preparePublication(StageCode $stage, StageSucceeded $outcome): void
    {
        $repository = $this->registry->resultRepository($stage);
        if ($repository instanceof StagePublicationPreparation) {
            $repository->preparePublication($outcome->result);
        }
    }

    /**
     * @param  array<string, ProcessUuid>  $ids  precreated stable IDs: event, projection, feed, incident*
     * @param  \Closure(int): array{work: ProcessUuid, event: ProcessUuid, projection: ProcessUuid}  $successorIds
     */
    public function finishLocked(
        AuthoritySnapshot $snapshot,
        ExecutionLease $lease,
        StageOutcome|HandlerFaultEvidence $outcome,
        array $ids,
        \Closure $successorIds,
    ): FinishLockedResult|PrefixFailure {
        CallerTransaction::require('Work finalize');
        [$publication, $locks] = $this->resolvePublicationLocks($outcome, $snapshot->input, $lease->stage);
        $prefix = $this->authority->lockPrefix($snapshot, skipLockedWork: false, publication: $locks);
        if ($prefix === PrefixFailure::WorkMissing) {
            return new FinishLockedResult(CompletionResult::invariantViolation());
        }
        if ($prefix instanceof PrefixFailure) {
            return $prefix;
        }
        [$failure, $handlerVersion] = $this->validateLockedOwnership($snapshot, $prefix, $lease);
        if ($failure !== null) {
            return $failure;
        }
        /** @var LedgerRow $group */
        $group = $prefix->group;
        /** @var string $handlerVersion */
        $work = $prefix->work;

        // Supersede пишет release/attempt: exact membership и attempt guards обязательны и здесь.
        if (! $prefix->intentActive || $this->resolver->inputInvalidated($snapshot->input, $snapshot->inputRevision, $prefix->subjects)) {
            $this->works->supersedeLocked($snapshot, $prefix, $ids['event'], $ids['projection'], ActorKind::Worker);
            $this->outbox->enqueueFeederWake($ids['feed'], $this->policy->parentLane($lease->stage));

            return new FinishLockedResult(
                CompletionResult::applied(WorkState::Superseded),
                accepted: $this->acceptedDecision($lease->workId, WorkState::Superseded, 0),
            );
        }

        $current = $this->works->lockedCheckpoint($work);
        $decision = $this->decide($lease, $outcome, $current, $work, $handlerVersion, $snapshot->input);
        if ($decision === null) {
            return new FinishLockedResult(CompletionResult::invariantViolation());
        }

        $this->applyDecision(
            $lease,
            $work,
            $group,
            count($prefix->members),
            $decision,
            $snapshot->input,
            $publication,
            $ids,
            $successorIds,
            $handlerVersion,
        );

        return new FinishLockedResult(
            CompletionResult::applied($decision->state),
            accepted: $this->acceptedDecision($lease->workId, $decision->state, $decision->itemIncrement),
        );
    }

    /**
     * @return array{0: ?StagePublicationRepository, 1: ?PublicationLocks}
     */
    private function resolvePublicationLocks(
        StageOutcome|HandlerFaultEvidence $outcome,
        WorkInput $input,
        StageCode $stage,
    ): array {
        $repository = ($outcome instanceof StageSucceeded || $outcome instanceof StageFailedItem)
            ? $this->registry->resultRepository($stage)
            : null;
        $publication = $repository instanceof StagePublicationRepository ? $repository : null;
        $locks = $publication !== null && $outcome instanceof StageSucceeded
            ? $publication->publicationLocks($input, $outcome->result)
            : null;

        return [$publication, $locks];
    }

    /**
     * @return array{0: ?FinishLockedResult, 1: ?string}
     */
    private function validateLockedOwnership(
        AuthoritySnapshot $snapshot,
        LockedPrefix $prefix,
        ExecutionLease $lease,
    ): array {
        $work = $prefix->work;
        // Ownership fence: recovery/cancel/supersede уже увеличили generation — ноль domain writes.
        if ($work->int('generation') !== $lease->generation
            || $work->nullableString('owner_token') !== $lease->token->value
            || $work->string('state') !== WorkState::Running->value) {
            return [new FinishLockedResult(CompletionResult::lostOwnership()), null];
        }
        $group = $prefix->group;
        if ($group === null
            || ! $this->authority->exactMembership($snapshot, $prefix, 'running')
            || $group->nullableString('attempt_id') !== $lease->attemptId->value) {
            return [new FinishLockedResult(CompletionResult::invariantViolation(), membershipCorrupt: true), null];
        }
        $attempt = LedgerRow::one(
            'SELECT handler_version FROM prc_process_attempts WHERE id = ? AND work_id = ? AND generation = ? AND finished_at IS NULL',
            [$lease->attemptId->value, $lease->workId->value->value, $lease->generation],
        );
        if ($attempt === null) {
            return [new FinishLockedResult(CompletionResult::invariantViolation(), membershipCorrupt: true), null];
        }

        return [null, $attempt->string('handler_version')];
    }

    /**
     * @param  array<string, ProcessUuid>  $ids
     * @param  \Closure(int): array{work: ProcessUuid, event: ProcessUuid, projection: ProcessUuid}  $successorIds
     */
    private function applyDecision(
        ExecutionLease $lease,
        LedgerRow $work,
        LedgerRow $group,
        int $memberCount,
        FinalizationDecision $decision,
        WorkInput $input,
        ?StagePublicationRepository $publication,
        array $ids,
        \Closure $successorIds,
        string $handlerVersion,
    ): void {
        $envelope = $decision->clearCheckpoint ? null : $decision->checkpoint;
        $payload = $this->registry->payloadCodec()->encodeCheckpoint($envelope);
        [$dueSql, $dueBindings] = $decision->due;
        $row = LedgerRow::one(
            "UPDATE prc_process_work
             SET state = ?, reason_code = ?, due_at = {$dueSql},
                 provider_recovery_due_at = CASE WHEN ?::int = 1 THEN clock_timestamp() + make_interval(secs => ?) ELSE NULL END,
                 checkpoint = ?::jsonb, checkpoint_schema_version = ?, checkpoint_seq = checkpoint_seq + ?,
                 item_failures = item_failures + ?, owner_token = NULL, lease_until = NULL, dispatch_claim_until = NULL,
                 transition_seq = transition_seq + 1, updated_at = clock_timestamp()
             WHERE id = ? AND generation = ? AND owner_token = ? AND state = 'running'
             RETURNING transition_seq",
            [
                $decision->state->value, $decision->reason, ...$dueBindings,
                $decision->providerRecovery ? 1 : 0, self::PROVIDER_RECOVERY_SECONDS,
                $payload->json, $payload->schemaVersion, $decision->progressSaved ? 1 : 0,
                $decision->itemIncrement, $lease->workId->value->value, $lease->generation, $lease->token->value,
            ],
        ) ?? throw new \LogicException('Finalize fence did not match the locked running work.');
        $closed = DB::update(
            "UPDATE prc_process_attempts SET finished_at = clock_timestamp(), terminal_kind = ?, fault_signature = decode(?, 'hex')
             WHERE id = ? AND work_id = ? AND generation = ? AND finished_at IS NULL",
            [$decision->terminalKind, $decision->faultSignature?->hex, $lease->attemptId->value, $lease->workId->value->value, $lease->generation],
        );
        if ($closed !== 1) {
            throw new \LogicException('Finalize must close exactly the current attempt.');
        }
        $this->works->releaseGroupLocked($group, $memberCount, $decision->terminalKind);
        $this->storeSuccessors($lease->workId, $work, $decision->successors, $successorIds);
        if (in_array($decision->state, [WorkState::Succeeded, WorkState::FailedItem], true)) {
            $publication?->afterSuccessors($input, $lease->workId, $ids['incident_event'], $ids['incident_projection']);
            if (($intentId = $work->nullableString('intent_id')) !== null) {
                $this->intents->releaseLocked($intentId);
            }
        }
        $this->openDecisionIncidents($lease, $decision, $handlerVersion, $ids);
        $this->transitions->recordWork(new TransitionData(
            eventId: $ids['event'],
            projectionMessageId: $ids['projection'],
            entityId: $lease->workId->value,
            entitySeq: $row->int('transition_seq'),
            previousState: WorkState::Running->value,
            newState: $decision->state->value,
            reasonCode: $decision->reason,
            actor: ActorKind::Worker,
            generation: $lease->generation,
        ));
        $this->outbox->enqueueFeederWake($ids['feed'], $this->policy->parentLane($lease->stage));
    }

    /**
     * @param  array<string, ProcessUuid>  $ids
     */
    private function openDecisionIncidents(
        ExecutionLease $lease,
        FinalizationDecision $decision,
        string $handlerVersion,
        array $ids,
    ): void {
        if ($decision->exhausted !== null) {
            $this->incidents->open(
                fingerprint: ContentHash::of('handler-fault-limit:v1:'.$lease->workId->value->value.':'.$handlerVersion),
                scopeKey: 'work:'.$lease->workId->value->value,
                kind: BlockedReason::HandlerFaultLimit->value,
                evidence: ['work_id' => $lease->workId->value->value, 'handler_version' => $handlerVersion, 'faults' => $decision->exhausted],
                incidentId: $ids['incident'],
                eventId: $ids['incident_event'],
                projectionMessageId: $ids['incident_projection'],
            );
        }
        if ($decision->state === WorkState::Blocked && $decision->reason === BlockedReason::ChunkConflict->value) {
            $this->incidents->open(
                fingerprint: ContentHash::of('publication-conflict:v1:'.$lease->workId->value->value),
                scopeKey: 'work:'.$lease->workId->value->value,
                kind: BlockedReason::ChunkConflict->value,
                evidence: ['work_id' => $lease->workId->value->value, 'stage' => $lease->stage->value],
                incidentId: $ids['incident'],
                eventId: $ids['incident_event'],
                projectionMessageId: $ids['incident_projection'],
            );
        }
    }

    /**
     * Выбор состояния по closed outcome и stage domain writes под locks.
     * null — недопустимый outcome (blocked_unknown без pinned linkage): ноль записей.
     */
    private function decide(
        ExecutionLease $lease,
        StageOutcome|HandlerFaultEvidence $outcome,
        ?WorkCheckpointEnvelope $current,
        LedgerRow $work,
        string $handlerVersion,
        WorkInput $input,
    ): ?FinalizationDecision {
        $results = $this->registry->resultRepository($lease->stage);
        if ($outcome instanceof StageSucceeded) {
            return new FinalizationDecision(
                state: WorkState::Succeeded,
                terminalKind: 'succeeded',
                checkpoint: $current,
                clearCheckpoint: true,
                successors: $results->storeResult($input, $outcome->result),
            );
        }
        if ($outcome instanceof StagePartial) {
            $progress = $lease->guardProgress($outcome->checkpoint);
            $results->storeProgress($input, $progress);

            return new FinalizationDecision(
                state: WorkState::Ready,
                terminalKind: 'partial',
                checkpoint: WorkStoreRepository::mergeProgress($current, $progress),
                progressSaved: true,
            );
        }
        if ($outcome instanceof StageDeferred) {
            $retryAt = $outcome->retryAt->value->format('Y-m-d H:i:s.uP');
            $jitter = random_int(0, self::JITTER_PERMILLE) / 1000;

            return new FinalizationDecision(
                state: WorkState::Deferred,
                terminalKind: 'deferred',
                reason: $outcome->reason->value,
                due: ["GREATEST(?::timestamptz, clock_timestamp()) + GREATEST(?::timestamptz - clock_timestamp(), interval '0') * ?", [$retryAt, $retryAt, $jitter]],
                checkpoint: $current,
            );
        }
        if ($outcome instanceof StageBlocked) {
            $unknown = $outcome->reason === BlockedReason::ProviderResultUnknown;
            // Repository запрещает blocked_unknown без exact pinned linkage в checkpoint envelope.
            $plan = $current?->providerRecoveryPlan;
            $pinned = $plan instanceof ProviderRecoveryPlan && $plan->mode === ProviderRecoveryMode::BlockedUnknown;
            if ($unknown && ! $pinned) {
                return null;
            }

            return new FinalizationDecision(
                state: WorkState::Blocked,
                terminalKind: 'blocked',
                reason: $outcome->reason->value,
                providerRecovery: $unknown,
                checkpoint: $current,
            );
        }
        if ($outcome instanceof StageFailedItem) {
            $failures = $work->int('item_failures') + 1;
            if ($failures >= self::ITEM_FAILURE_LIMIT) {
                return new FinalizationDecision(
                    state: WorkState::FailedItem,
                    terminalKind: 'failed_item',
                    reason: $outcome->reason->value,
                    checkpoint: $current,
                    clearCheckpoint: true,
                    itemIncrement: 1,
                );
            }

            return new FinalizationDecision(
                state: WorkState::Deferred,
                terminalKind: 'failed_item',
                reason: DeferralReason::ItemRetry->value,
                due: ['clock_timestamp() + make_interval(secs => ?)', [$failures === 1 ? self::FIRST_ITEM_RETRY_SECONDS : self::SECOND_ITEM_RETRY_SECONDS]],
                checkpoint: $current,
                itemIncrement: 1,
            );
        }
        if ($outcome instanceof HandlerFaultEvidence) {
            // Authority budget — finished attempts этой work и executing handler version, без окна времени.
            $faults = 1 + (LedgerRow::one(
                'SELECT count(*) AS faults FROM prc_process_attempts
                 WHERE work_id = ? AND handler_version = ? AND fault_signature IS NOT NULL AND finished_at IS NOT NULL',
                [$lease->workId->value->value, $handlerVersion],
            )?->int('faults') ?? 0);
            if ($faults >= $this->policy->maxHandlerFaultsPerWorkVersion) {
                return new FinalizationDecision(
                    state: WorkState::Blocked,
                    terminalKind: 'blocked',
                    reason: BlockedReason::HandlerFaultLimit->value,
                    checkpoint: $current,
                    faultSignature: $outcome->signature,
                    exhausted: $faults,
                );
            }

            return new FinalizationDecision(
                state: WorkState::Deferred,
                terminalKind: 'deferred',
                reason: DeferralReason::HandlerFault->value,
                due: ['clock_timestamp() + make_interval(secs => ?)', [self::HANDLER_FAULT_DELAY_SECONDS]],
                checkpoint: $current,
                faultSignature: $outcome->signature,
            );
        }

        throw new \LogicException('Unknown StageOutcome variant.');
    }

    /**
     * Ready successors идемпотентно по work_key (существующий key обязан иметь тот же immutable
     * identity) и required/observes links всех runs текущей work. Run rows lock-аются sorted в
     * domain tier; cancelled run не получает новых required links.
     *
     * @param  list<WorkInput>  $successors
     * @param  \Closure(int): array{work: ProcessUuid, event: ProcessUuid, projection: ProcessUuid}  $successorIds
     */
    private function storeSuccessors(WorkId $workId, LedgerRow $work, array $successors, \Closure $successorIds): void
    {
        if ($successors === []) {
            return;
        }
        $runs = $this->works->lockRunLinks($workId);
        foreach ($successors as $index => $input) {
            $identity = new WorkIdentity($input);
            $existing = LedgerRow::one(
                "SELECT id, work_identity = ?::jsonb AS same FROM prc_process_work WHERE work_key = decode(?, 'hex')",
                [$identity->payload()->json, $identity->key()->hex],
            );
            if ($existing !== null && ! $existing->bool('same')) {
                throw new \LogicException('Successor work_key collides with a different immutable identity.');
            }
            $successor = $successorIds($index);
            $successorId = $existing === null ? new WorkId($successor['work']) : new WorkId(new ProcessUuid($existing->string('id')));
            if ($existing === null) {
                $this->works->storeWork(new StoreWorkData(
                    id: $successorId,
                    input: $input,
                    inputRevision: 1,
                    intentId: $input instanceof DetailInput ? $input->intentId
                        : ($input instanceof DownloadInput ? $input->acquisitionIntentId : null),
                    cohortKey: $work->string('cohort_key'),
                    platformId: $work->nullableInt('platform_id'),
                    originRunId: ($origin = $work->nullableString('origin_run_id')) === null ? null : new ProcessUuid($origin),
                    priority: 100,
                    eventId: $successor['event'],
                    projectionMessageId: $successor['projection'],
                    identity: $identity,
                ));
            }
            foreach ($runs as $run) {
                if ($run->bool('cancelled') && $run->string('accounting_role') === 'required') {
                    continue;
                }
                $this->works->upsertDiscoveredRunLink($run->string('run_id'), $successorId, $run->string('accounting_role'));
            }
        }
    }

    /**
     * Принятое решение уже лежит в строке work: reason_code и due_at для envelope.
     * item_increment — приращение этой tx к item_failures, не накопленный счётчик.
     *
     * @return array{state: WorkState, reason: string|null, due_at: string|null, item_increment: int}
     */
    private function acceptedDecision(WorkId $workId, WorkState $state, int $itemIncrement): array
    {
        $row = LedgerRow::one(
            'SELECT reason_code, due_at::text AS due_at FROM prc_process_work WHERE id = ?',
            [$workId->value->value],
        ) ?? throw new \LogicException('Accepted finalization did not leave a work row.');
        $due = $row->nullableString('due_at');

        return [
            'state' => $state,
            'reason' => $row->nullableString('reason_code'),
            'due_at' => $due === null ? null : new \DateTimeImmutable($due)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'item_increment' => $itemIncrement,
        ];
    }
}
