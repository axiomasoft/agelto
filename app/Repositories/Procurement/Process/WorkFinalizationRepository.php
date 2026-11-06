<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Dto\Procurement\Process\AuthoritySnapshot;
use App\Dto\Procurement\Process\CompletionResult;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\DetailInput;
use App\Dto\Procurement\Process\DownloadInput;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\HandlerFaultEvidence;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\ProviderRecoveryPlan;
use App\Dto\Procurement\Process\StageBlocked;
use App\Dto\Procurement\Process\StageDeferred;
use App\Dto\Procurement\Process\StageFailedItem;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\StagePartial;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Dto\Procurement\Process\WorkCheckpointEnvelope;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkIdentity;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Process\ActorKind;
use App\Enums\Procurement\Process\BlockedReason;
use App\Enums\Procurement\Process\DeferralReason;
use App\Enums\Procurement\Process\PrefixFailure;
use App\Enums\Procurement\Process\ProviderRecoveryMode;
use App\Enums\Procurement\Process\WorkState;
use App\Services\Procurement\Process\Contracts\StagePublicationRepository;
use App\Services\Procurement\Process\ProcessPolicy;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Process\SubjectLockResolver;
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

    /**
     * @param  array<string, ProcessUuid>  $ids  precreated stable IDs: event, projection, feed, incident*
     * @param  \Closure(int): array{work: ProcessUuid, event: ProcessUuid, projection: ProcessUuid}  $successorIds
     * @param  array{state: WorkState, reason: string|null, due_at: string|null, item_increment: int}|null  $accepted
     */
    public function finishLocked(
        AuthoritySnapshot $snapshot,
        ExecutionLease $lease,
        StageOutcome|HandlerFaultEvidence $outcome,
        array $ids,
        \Closure $successorIds,
        bool &$membershipCorrupt,
        ?array &$accepted = null,
    ): CompletionResult|PrefixFailure {
        CallerTransaction::require('Work finalize');
        $accepted = null;
        $publication = ($outcome instanceof StageSucceeded || $outcome instanceof StageFailedItem)
            ? $this->registry->resultRepository($lease->stage)
            : null;
        $publication = $publication instanceof StagePublicationRepository ? $publication : null;
        $locks = $publication !== null && $outcome instanceof StageSucceeded
            ? $publication->publicationLocks($snapshot->input, $outcome->result) : null;
        $prefix = $this->authority->lockPrefix($snapshot, skipLockedWork: false, publication: $locks);
        if ($prefix === PrefixFailure::WorkMissing) {
            return CompletionResult::invariantViolation();
        }
        if ($prefix instanceof PrefixFailure) {
            return $prefix;
        }
        $work = $prefix->work;
        // Ownership fence: recovery/cancel/supersede уже увеличили generation — ноль domain writes.
        if ($work->int('generation') !== $lease->generation || $work->nullableString('owner_token') !== $lease->token->value
            || $work->string('state') !== WorkState::Running->value) {
            return CompletionResult::lostOwnership();
        }
        $group = $prefix->group;
        if ($group === null || ! $this->authority->exactMembership($snapshot, $prefix, 'running')
            || $group->nullableString('attempt_id') !== $lease->attemptId->value) {
            $membershipCorrupt = true;

            return CompletionResult::invariantViolation();
        }
        $attempt = LedgerRow::one(
            'SELECT handler_version FROM prc_process_attempts WHERE id = ? AND work_id = ? AND generation = ? AND finished_at IS NULL',
            [$lease->attemptId->value, $lease->workId->value->value, $lease->generation],
        );
        if ($attempt === null) {
            $membershipCorrupt = true;

            return CompletionResult::invariantViolation();
        }

        // Supersede пишет release/attempt: exact membership и attempt guards обязательны и здесь.
        if (! $prefix->intentActive || $this->resolver->inputInvalidated($snapshot->input, $snapshot->inputRevision, $prefix->subjects)) {
            $this->works->supersedeLocked($snapshot, $prefix, $ids['event'], $ids['projection'], ActorKind::Worker);
            $this->outbox->enqueueFeederWake($ids['feed'], $this->policy->parentLane($lease->stage));
            $accepted = $this->acceptedDecision($lease->workId, WorkState::Superseded, 0);

            return CompletionResult::applied(WorkState::Superseded);
        }

        $codec = $this->registry->payloadCodec();
        $current = $this->works->lockedCheckpoint($work);
        $decision = $this->decide($lease, $outcome, $current, $work, $attempt->string('handler_version'), $snapshot->input);
        if ($decision === null) {
            return CompletionResult::invariantViolation();
        }
        $successors = $decision['successors'];

        $envelope = $decision['clear_checkpoint'] ? null : $decision['checkpoint'];
        $payload = $codec->encodeCheckpoint($envelope);
        [$dueSql, $dueBindings] = $decision['due'];
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
                $decision['state']->value, $decision['reason'], ...$dueBindings,
                $decision['provider_recovery'] ? 1 : 0, self::PROVIDER_RECOVERY_SECONDS,
                $payload->json, $payload->schemaVersion, $decision['progress_saved'] ? 1 : 0,
                $decision['item_increment'], $lease->workId->value->value, $lease->generation, $lease->token->value,
            ],
        ) ?? throw new \LogicException('Finalize fence did not match the locked running work.');
        $closed = DB::update(
            "UPDATE prc_process_attempts SET finished_at = clock_timestamp(), terminal_kind = ?, fault_signature = decode(?, 'hex')
             WHERE id = ? AND work_id = ? AND generation = ? AND finished_at IS NULL",
            [$decision['terminal_kind'], $decision['fault_signature']?->hex, $lease->attemptId->value, $lease->workId->value->value, $lease->generation],
        );
        if ($closed !== 1) {
            throw new \LogicException('Finalize must close exactly the current attempt.');
        }
        $this->works->releaseGroupLocked($group, count($prefix->members), $decision['terminal_kind']);
        $this->storeSuccessors($lease->workId, $work, $successors, $successorIds);
        if (in_array($decision['state'], [WorkState::Succeeded, WorkState::FailedItem], true)) {
            $publication?->afterSuccessors($snapshot->input, $lease->workId, $ids['incident_event'], $ids['incident_projection']);
        }
        // Terminal work завершает свой active intent и продвигает очередь intents своего subject/stage.
        if (($intentId = $work->nullableString('intent_id')) !== null
            && in_array($decision['state'], [WorkState::Succeeded, WorkState::FailedItem], true)) {
            $this->intents->releaseLocked($intentId);
        }
        if ($decision['exhausted'] !== null) {
            $this->incidents->open(
                fingerprint: ContentHash::of('handler-fault-limit:v1:'.$lease->workId->value->value.':'.$attempt->string('handler_version')),
                scopeKey: 'work:'.$lease->workId->value->value,
                kind: BlockedReason::HandlerFaultLimit->value,
                evidence: ['work_id' => $lease->workId->value->value, 'handler_version' => $attempt->string('handler_version'), 'faults' => $decision['exhausted']],
                incidentId: $ids['incident'],
                eventId: $ids['incident_event'],
                projectionMessageId: $ids['incident_projection'],
            );
        }
        if ($decision['state'] === WorkState::Blocked && $decision['reason'] === BlockedReason::ChunkConflict->value) {
            $this->incidents->open(
                fingerprint: ContentHash::of('publication-conflict:v1:'.$lease->workId->value->value),
                scopeKey: 'work:'.$lease->workId->value->value,
                kind: BlockedReason::ChunkConflict->value,
                evidence: ['work_id' => $lease->workId->value->value, 'stage' => $lease->stage->value],
                incidentId: $ids['incident'], eventId: $ids['incident_event'], projectionMessageId: $ids['incident_projection'],
            );
        }
        $this->transitions->recordWork(
            $ids['event'], $ids['projection'], $lease->workId->value, $row->int('transition_seq'),
            WorkState::Running->value, $decision['state']->value, $decision['reason'], ActorKind::Worker, $lease->generation,
        );
        $this->outbox->enqueueFeederWake($ids['feed'], $this->policy->parentLane($lease->stage));
        $accepted = $this->acceptedDecision($lease->workId, $decision['state'], $decision['item_increment']);

        return CompletionResult::applied($decision['state']);
    }

    /**
     * Выбор состояния по closed outcome и stage domain writes под locks.
     * null — недопустимый outcome (blocked_unknown без pinned linkage): ноль записей.
     *
     * @return array{state: WorkState, reason: string|null, due: array{string, list<mixed>}, provider_recovery: bool,
     *     checkpoint: WorkCheckpointEnvelope|null, clear_checkpoint: bool, progress_saved: bool, item_increment: int,
     *     terminal_kind: string, fault_signature: ContentHash|null, exhausted: int|null, successors: list<WorkInput>}|null
     */
    private function decide(
        ExecutionLease $lease,
        StageOutcome|HandlerFaultEvidence $outcome,
        ?WorkCheckpointEnvelope $current,
        LedgerRow $work,
        string $handlerVersion,
        WorkInput $input,
    ): ?array {
        $base = [
            'reason' => null, 'due' => ['NULL', []], 'provider_recovery' => false, 'checkpoint' => $current,
            'clear_checkpoint' => false, 'progress_saved' => false, 'item_increment' => 0, 'fault_signature' => null,
            'exhausted' => null, 'successors' => [],
        ];
        $results = $this->registry->resultRepository($lease->stage);
        if ($outcome instanceof StageSucceeded) {
            return [...$base, 'state' => WorkState::Succeeded, 'clear_checkpoint' => true, 'terminal_kind' => 'succeeded',
                'successors' => $results->storeResult($input, $outcome->result)];
        }
        if ($outcome instanceof StagePartial) {
            $progress = $lease->guardProgress($outcome->checkpoint);
            $results->storeProgress($input, $progress);

            return [...$base, 'state' => WorkState::Ready, 'checkpoint' => WorkStoreRepository::mergeProgress($current, $progress),
                'progress_saved' => true, 'terminal_kind' => 'partial'];
        }
        if ($outcome instanceof StageDeferred) {
            $retryAt = $outcome->retryAt->value->format('Y-m-d H:i:s.uP');
            $jitter = random_int(0, self::JITTER_PERMILLE) / 1000;

            return [...$base, 'state' => WorkState::Deferred, 'reason' => $outcome->reason->value, 'terminal_kind' => 'deferred',
                'due' => ["GREATEST(?::timestamptz, clock_timestamp()) + GREATEST(?::timestamptz - clock_timestamp(), interval '0') * ?", [$retryAt, $retryAt, $jitter]]];
        }
        if ($outcome instanceof StageBlocked) {
            $unknown = $outcome->reason === BlockedReason::ProviderResultUnknown;
            // Repository запрещает blocked_unknown без exact pinned linkage в checkpoint envelope.
            $plan = $current?->providerRecoveryPlan;
            $pinned = $plan instanceof ProviderRecoveryPlan && $plan->mode === ProviderRecoveryMode::BlockedUnknown;
            if ($unknown && ! $pinned) {
                return null;
            }

            return [...$base, 'state' => WorkState::Blocked, 'reason' => $outcome->reason->value, 'provider_recovery' => $unknown, 'terminal_kind' => 'blocked'];
        }
        if ($outcome instanceof StageFailedItem) {
            $failures = $work->int('item_failures') + 1;
            if ($failures >= self::ITEM_FAILURE_LIMIT) {
                return [...$base, 'state' => WorkState::FailedItem, 'reason' => $outcome->reason->value, 'clear_checkpoint' => true,
                    'item_increment' => 1, 'terminal_kind' => 'failed_item'];
            }

            return [...$base, 'state' => WorkState::Deferred, 'reason' => DeferralReason::ItemRetry->value, 'item_increment' => 1,
                'terminal_kind' => 'failed_item', 'due' => ['clock_timestamp() + make_interval(secs => ?)', [$failures === 1 ? self::FIRST_ITEM_RETRY_SECONDS : self::SECOND_ITEM_RETRY_SECONDS]]];
        }
        if ($outcome instanceof HandlerFaultEvidence) {
            // Authority budget — finished attempts этой work и executing handler version, без окна времени.
            $faults = 1 + (LedgerRow::one(
                'SELECT count(*) AS faults FROM prc_process_attempts
                 WHERE work_id = ? AND handler_version = ? AND fault_signature IS NOT NULL AND finished_at IS NOT NULL',
                [$lease->workId->value->value, $handlerVersion],
            )?->int('faults') ?? 0);
            $fault = [...$base, 'fault_signature' => $outcome->signature];
            if ($faults >= $this->policy->maxHandlerFaultsPerWorkVersion) {
                return [...$fault, 'state' => WorkState::Blocked, 'reason' => BlockedReason::HandlerFaultLimit->value,
                    'terminal_kind' => 'blocked', 'exhausted' => $faults];
            }

            return [...$fault, 'state' => WorkState::Deferred, 'reason' => DeferralReason::HandlerFault->value, 'terminal_kind' => 'deferred',
                'due' => ['clock_timestamp() + make_interval(secs => ?)', [self::HANDLER_FAULT_DELAY_SECONDS]]];
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
                $this->works->storeWork(
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
                );
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
            'due_at' => $due === null ? null : (new \DateTimeImmutable($due))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'item_increment' => $itemIncrement,
        ];
    }
}
