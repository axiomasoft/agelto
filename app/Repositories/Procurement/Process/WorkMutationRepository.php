<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Dto\Procurement\Process\AuthoritySnapshot;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\DetailInput;
use App\Dto\Procurement\Process\DownloadInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\ProviderRecoveryPlan;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkIdentity;
use App\Dto\Procurement\Process\WorkMutationResult;
use App\Enums\Procurement\Process\ActorKind;
use App\Enums\Procurement\Process\BlockedReason;
use App\Enums\Procurement\Process\PrefixFailure;
use App\Enums\Procurement\Process\ProviderRecoveryMode;
use App\Enums\Procurement\Process\WorkMutationStatus;
use App\Enums\Procurement\Process\WorkState;
use App\Services\Procurement\Process\Contracts\ProviderRecoveryResolver;
use App\Services\Procurement\Process\ProcessPolicy;
use App\Services\Procurement\Process\SubjectLockResolver;
use Illuminate\Support\Facades\DB;

/**
 * Управляющие мутации ledger под полным prefix: CancelWork —
 * hard cancel конкретного nonterminal work, CancelRun — отмена намерения run без слепой отмены
 * shared work, RetryHandlerFaultedWork — новый explicit manual intent/work key вместо исчерпанного
 * blocked(handler_fault_limit). Все методы выполняются в ProcessTransaction вызывающего Action;
 * RBAC проверяет caller, bypass state через adapters запрещён. Generation+1 отзывает владельца,
 * поздняя публикация невозможна; история (attempts, transitions, run links) не удаляется.
 */
final readonly class WorkMutationRepository
{
    private const int REASON_LIMIT = 200;

    public function __construct(
        private AuthorityRepository $authority,
        private WorkStoreRepository $works,
        private TransitionRepository $transitions,
        private OutboxRepository $outbox,
        private SubjectLockResolver $resolver,
        private ProcessPolicy $policy,
        private IntentRepository $intents,
    ) {}

    /**
     * Hard cancel: generation+1, token/lease/claim/due/provider_recovery_due_at/checkpoint очищены,
     * active attempt закрыт, вся group освобождена, transition+projection+feed wake. Уже claimed
     * provider send не отзывается; ещё не claimed send authorization инвалидируется resolver-ом.
     *
     * @param  array<string, ProcessUuid>  $ids  event, projection, feed
     */
    public function cancelWork(
        AuthoritySnapshot $snapshot,
        string $reason,
        ?int $expectedTransitionSeq,
        ?int $actorId,
        ProviderRecoveryResolver $provider,
        array $ids,
    ): WorkMutationResult|PrefixFailure {
        CallerTransaction::require('Work cancel');
        $reason = self::reason($reason);
        $prefix = $this->authority->lockPrefix($snapshot, skipLockedWork: false);
        if ($prefix === PrefixFailure::WorkMissing) {
            return WorkMutationResult::refused(WorkMutationStatus::NotFound);
        }
        if ($prefix instanceof PrefixFailure) {
            return $prefix;
        }
        $work = $prefix->work;
        $state = WorkState::from($work->string('state'));
        if (in_array($state, [WorkState::Succeeded, WorkState::FailedItem, WorkState::Cancelled, WorkState::Superseded], true)) {
            return WorkMutationResult::refused(WorkMutationStatus::Terminal);
        }
        if ($expectedTransitionSeq !== null && $work->int('transition_seq') !== $expectedTransitionSeq) {
            return WorkMutationResult::refused(WorkMutationStatus::VersionConflict);
        }
        if (in_array($state, [WorkState::Admitted, WorkState::Running], true)) {
            if (! $this->authority->exactMembership($snapshot, $prefix, $state->value)) {
                throw new \LogicException('Cancel requires the exact active admission group.');
            }
            if ($state === WorkState::Running) {
                $attempt = LedgerRow::one('SELECT id FROM prc_process_attempts WHERE work_id = ? AND generation = ? AND finished_at IS NULL', [$snapshot->workId->value->value, $work->int('generation')]);
                if ($attempt === null || $prefix->group?->nullableString('attempt_id') !== $attempt->string('id')) {
                    throw new \LogicException('Cancel requires the active group to belong to the current attempt.');
                }
            }
        } elseif ($prefix->group !== null) {
            throw new \LogicException('Non-admitted work cannot own an active admission group.');
        }

        $provider->revokeUnclaimedLocked($snapshot->workId);
        $generation = $work->int('generation');
        // Blocked provider_result_unknown: pinned plan остаётся durable до settlement reconciler-ом.
        // Работа уходит из blocked-selector, но не из provider-recovery; due у cancelled запрещён схемой.
        $plan = $this->works->lockedCheckpoint($work)?->providerRecoveryPlan;
        $keepPlan = $state === WorkState::Blocked
            && $plan instanceof ProviderRecoveryPlan
            && $plan->mode === ProviderRecoveryMode::BlockedUnknown;
        $row = LedgerRow::one(
            "UPDATE prc_process_work
             SET state = 'cancelled', reason_code = 'cancelled', generation = generation + 1, owner_token = NULL, lease_until = NULL,
                 dispatch_claim_until = NULL, due_at = NULL, provider_recovery_due_at = NULL,
                 checkpoint = CASE WHEN ?::boolean THEN checkpoint ELSE NULL END,
                 checkpoint_schema_version = CASE WHEN ?::boolean THEN checkpoint_schema_version ELSE NULL END,
                 transition_seq = transition_seq + 1, updated_at = clock_timestamp()
             WHERE id = ? AND generation = ? AND state IN ('ready', 'deferred', 'admitted', 'running', 'blocked')
             RETURNING generation, transition_seq",
            [$keepPlan, $keepPlan, $snapshot->workId->value->value, $generation],
        ) ?? throw new \LogicException('Cancel fence did not match the locked work.');
        if ($state === WorkState::Running) {
            $closed = DB::update(
                "UPDATE prc_process_attempts SET finished_at = clock_timestamp(), terminal_kind = 'cancelled'
                 WHERE work_id = ? AND generation = ? AND finished_at IS NULL",
                [$snapshot->workId->value->value, $generation],
            );
            if ($closed !== 1) {
                throw new \LogicException('Running cancel must close exactly the current attempt.');
            }
        }
        if ($prefix->group !== null) {
            $this->works->releaseGroupLocked($prefix->group, count($prefix->members), 'cancelled');
        }
        $this->transitions->recordWork(
            $ids['event'], $ids['projection'], $snapshot->workId->value, $row->int('transition_seq'),
            $state->value, WorkState::Cancelled->value, 'cancelled', $actorId === null ? ActorKind::System : ActorKind::Operator,
            $row->int('generation'), $actorId, ['reason' => $reason],
        );
        // Cancelled work без successor освобождает свой intent: очередь subject/stage не зависает.
        if (($intentId = $work->nullableString('intent_id')) !== null) {
            $this->intents->releaseLocked($intentId);
        }
        $this->outbox->enqueueFeederWake($ids['feed'], $this->policy->parentLane($snapshot->handler->stage));

        return new WorkMutationResult(WorkMutationStatus::Applied);
    }

    /**
     * CancelRun: run row FOR UPDATE с expected transition_seq. cancel_requested_at, collecting →
     * incomplete(reason=cancelled), discovery_closed_at и audit event атомарны. Shared work других
     * runs не отменяется: он может завершиться и сохранить provenance этого run.
     *
     * @param  array<string, ProcessUuid>  $ids  event, projection
     */
    public function cancelRun(ProcessUuid $runId, string $reason, ?int $expectedTransitionSeq, ?int $actorId, array $ids): WorkMutationResult
    {
        CallerTransaction::require('Run cancel');
        $reason = self::reason($reason);
        $run = LedgerRow::one(
            'SELECT discovery_outcome, transition_seq, cancel_requested_at IS NOT NULL AS cancelled FROM prc_process_runs WHERE id = ? FOR UPDATE',
            [$runId->value],
        );
        if ($run === null) {
            return WorkMutationResult::refused(WorkMutationStatus::NotFound);
        }
        if ($run->bool('cancelled')) {
            return WorkMutationResult::refused(WorkMutationStatus::Terminal);
        }
        if ($expectedTransitionSeq !== null && $run->int('transition_seq') !== $expectedTransitionSeq) {
            return WorkMutationResult::refused(WorkMutationStatus::VersionConflict);
        }
        $row = LedgerRow::one(
            "UPDATE prc_process_runs
             SET cancel_requested_at = clock_timestamp(),
                 discovery_outcome = CASE WHEN discovery_outcome = 'collecting' THEN 'incomplete' ELSE discovery_outcome END,
                 discovery_closed_at = COALESCE(discovery_closed_at, clock_timestamp()),
                 discovery_reason_code = CASE WHEN discovery_outcome = 'collecting' THEN 'cancelled' ELSE discovery_reason_code END,
                 transition_seq = transition_seq + 1
             WHERE id = ? AND cancel_requested_at IS NULL
             RETURNING discovery_outcome, transition_seq",
            [$runId->value],
        ) ?? throw new \LogicException('Run cancel fence did not match the locked run.');
        $this->transitions->recordRun(
            $ids['event'], $ids['projection'], $runId, $row->int('transition_seq'),
            $run->string('discovery_outcome'), $row->string('discovery_outcome'), 'cancelled',
            $actorId === null ? ActorKind::System : ActorKind::Operator, $actorId, ['reason' => $reason],
        );

        return new WorkMutationResult(WorkMutationStatus::Applied);
    }

    /**
     * RetryHandlerFaultedWork: прежний blocked(handler_fault_limit) fenced → superseded, прежний
     * active intent → terminal, новый manual intent (intent_key = commandId, поэтому повтор той же
     * команды — Duplicate) → active, successor work с новым work_key и свежим budget → ready;
     * required run links переносятся (старые становятся observes: история сохранена). Автоматический
     * scheduler эту мутацию не вызывает.
     *
     * @param  array<string, ProcessUuid>  $ids  intent, work, work_event, work_projection, event, projection, feed
     */
    public function retryFaulted(
        AuthoritySnapshot $snapshot,
        int $expectedTransitionSeq,
        ProcessUuid $commandId,
        string $reason,
        ?int $actorId,
        array $ids,
        bool $failedItem = false,
    ): WorkMutationResult|PrefixFailure {
        CallerTransaction::require('Handler fault retry');
        $reason = self::reason($reason);
        $input = $snapshot->input;
        $prefix = $this->authority->lockPrefix($snapshot, skipLockedWork: false);
        if ($prefix === PrefixFailure::WorkMissing) {
            return WorkMutationResult::refused(WorkMutationStatus::NotFound);
        }
        if ($prefix instanceof PrefixFailure) {
            return $prefix;
        }
        $intentKey = ContentHash::of('manual-retry:v1:'.$commandId->value);
        $existing = LedgerRow::one(
            "SELECT w.id AS work_id, w.work_identity->'manual_retry'->>'retry_of_work_id' AS retry_of_work_id
             FROM prc_operation_intents i JOIN prc_process_work w ON w.intent_id = i.id WHERE i.intent_key = decode(?, 'hex')",
            [$intentKey->hex],
        );
        if ($existing !== null) {
            if ($existing->nullableString('retry_of_work_id') !== $snapshot->workId->value->value) {
                return WorkMutationResult::refused(WorkMutationStatus::VersionConflict);
            }
            $successor = $existing->nullableString('work_id');

            return new WorkMutationResult(WorkMutationStatus::Duplicate, $successor === null ? null : new WorkId(new ProcessUuid($successor)));
        }
        $work = $prefix->work;
        if ($work->int('transition_seq') !== $expectedTransitionSeq) {
            return WorkMutationResult::refused(WorkMutationStatus::VersionConflict);
        }
        if ($failedItem ? $work->string('state') !== WorkState::FailedItem->value
            : ($work->string('state') !== WorkState::Blocked->value || $work->nullableString('reason_code') !== BlockedReason::HandlerFaultLimit->value)) {
            return WorkMutationResult::refused(WorkMutationStatus::NotEligible);
        }
        if ($prefix->group !== null) {
            throw new \LogicException('Fault-limited work cannot retain an active admission group.');
        }
        if ((! $failedItem && ! $prefix->intentActive) || $this->resolver->inputInvalidated($input, $snapshot->inputRevision, $prefix->subjects)) {
            return WorkMutationResult::refused(WorkMutationStatus::VersionConflict);
        }
        $envelope = $this->works->lockedCheckpoint($work);
        if ($envelope?->providerRecoveryPlan instanceof ProviderRecoveryPlan) {
            return WorkMutationResult::refused(WorkMutationStatus::ProviderUnresolved);
        }

        $stage = $snapshot->handler->stage;
        $owner = $this->resolver->ownerSubject($stage, $prefix->subjects);
        $subject = $owner->subject;
        // Authority прежнего intent — колонка work, а при её отсутствии identity внутри immutable input.
        $oldIntentId = $work->nullableString('intent_id')
            ?? match (true) {
                $input instanceof DetailInput => $input->intentId->value,
                $input instanceof DownloadInput => $input->acquisitionIntentId->value,
                default => null,
            };
        $oldIntent = $oldIntentId === null ? null : LedgerRow::one(
            "SELECT encode(semantic_policy_hash, 'hex') AS semantic, encode(access_policy_hash, 'hex') AS access, cycle_seq, expected_head_generation
             FROM prc_operation_intents WHERE id = ?",
            [$oldIntentId],
        );
        DB::update("UPDATE prc_operation_intents SET state = 'terminal' WHERE id = ? AND state = 'active'", [$oldIntentId]);
        $newIntent = $ids['intent'];
        // Without an existing intent, the entire unchanged typed input is the conservative policy identity.
        $semantic = new ContentHash($oldIntent?->string('semantic') ?? $snapshot->inputHash->hex);
        $access = new ContentHash($oldIntent?->string('access') ?? $snapshot->inputHash->hex);
        $this->works->storeIntent(
            id: $newIntent,
            intentKey: $intentKey,
            stage: $stage,
            subject: $subject,
            semanticPolicyHash: $semantic,
            accessPolicyHash: $access,
            cycleSeq: ($oldIntent?->int('cycle_seq') ?? 0) + 1,
            expectedHeadGeneration: $owner->headGeneration,
            // Один active intent на subject/stage: если очередь уже продвинута, retry ждёт в pending.
            state: $this->intents->admissionState($stage->value, $subject->kind->value, is_int($subject->id) ? (string) $subject->id : $subject->id->value),
        );

        $generation = $work->int('generation');
        $row = $failedItem ? LedgerRow::one(
            "UPDATE prc_process_work SET transition_seq=transition_seq+1, updated_at=clock_timestamp()
             WHERE id=? AND generation=? AND state='failed_item' RETURNING generation,transition_seq",
            [$snapshot->workId->value->value, $generation],
        ) : LedgerRow::one(
            "UPDATE prc_process_work
             SET state = 'superseded', reason_code = 'manual_retry', generation = generation + 1, owner_token = NULL, lease_until = NULL,
                 dispatch_claim_until = NULL, due_at = NULL, provider_recovery_due_at = NULL,
                 checkpoint = NULL, checkpoint_schema_version = NULL, transition_seq = transition_seq + 1, updated_at = clock_timestamp()
             WHERE id = ? AND generation = ? AND state = 'blocked' AND reason_code = 'handler_fault_limit'
             RETURNING generation, transition_seq",
            [$snapshot->workId->value->value, $generation],
        );
        $row ??= throw new \LogicException('Manual retry fence did not match the locked work.');
        $this->transitions->recordWork(
            $ids['event'], $ids['projection'], $snapshot->workId->value, $row->int('transition_seq'),
            $failedItem ? WorkState::FailedItem->value : WorkState::Blocked->value,
            $failedItem ? WorkState::FailedItem->value : WorkState::Superseded->value, 'manual_retry', ActorKind::Operator,
            $row->int('generation'), $actorId, ['reason' => $reason, 'command_id' => $commandId->value, 'successor_work_id' => $ids['work']->value],
        );

        $priority = (LedgerRow::one('SELECT priority FROM prc_process_work WHERE id = ?', [$snapshot->workId->value->value])
            ?? throw new \LogicException('Locked work row disappeared.'))->int('priority');
        $successor = new WorkId($ids['work']);
        $originRun = $work->nullableString('origin_run_id');
        $this->works->storeWork(
            id: $successor,
            input: $input,
            inputRevision: $snapshot->inputRevision,
            intentId: $newIntent,
            cohortKey: $work->string('cohort_key'),
            platformId: $work->nullableInt('platform_id'),
            originRunId: $originRun === null ? null : new ProcessUuid($originRun),
            priority: $priority,
            eventId: $ids['work_event'],
            projectionMessageId: $ids['work_projection'],
            identity: new WorkIdentity($input, $newIntent, $snapshot->workId),
        );
        $this->works->carryRunLinks($snapshot->workId, $successor);
        $this->outbox->enqueueFeederWake($ids['feed'], $this->policy->parentLane($stage));

        return new WorkMutationResult(WorkMutationStatus::Applied, $successor);
    }

    private static function reason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > self::REASON_LIMIT || str_contains($reason, "\0")) {
            throw new \InvalidArgumentException('Control action requires a short nonempty reason.');
        }

        return $reason;
    }
}
