<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Dto\Procurement\Process\AuthoritySnapshot;
use App\Dto\Procurement\Process\CheckpointResult;
use App\Dto\Procurement\Process\ClaimResult;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\DbInstant;
use App\Dto\Procurement\Process\DeliveryGeneration;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\LeaseRenewal;
use App\Dto\Procurement\Process\NoProviderRecovery;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\StageCheckpoint;
use App\Dto\Procurement\Process\SubjectKey;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\Process\WorkCheckpointEnvelope;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkIdentity;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Process\AccountingRole;
use App\Enums\Procurement\Process\ActorKind;
use App\Enums\Procurement\Process\AuthorityGap;
use App\Enums\Procurement\Process\CheckpointStatus;
use App\Enums\Procurement\Process\ClaimStatus;
use App\Enums\Procurement\Process\DeferralReason;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\PrefixFailure;
use App\Enums\Procurement\Process\RunWorkRelation;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\WorkState;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Services\Procurement\Process\Contracts\LeaseKeeper;
use App\Services\Procurement\Process\DatabaseFailureClassifier;
use App\Services\Procurement\Process\ProcessPayloadCodec;
use App\Services\Procurement\Process\ProcessPolicy;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\SchemaSupportedStages;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Process\SubjectLockResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writer intents, новых ready work, admitted→running claim (P1.6/P1.7) и ownership живого attempt
 * (P1.8). Work ссылается на реальный subject одной owner column своей стадии; work_key =
 * WorkIdentity, input immutable (новое input — новый intent/work). Claim берёт полный prefix без
 * SKIP LOCKED (bp-sql-claim); heartbeat — единственный CAS work row без других locks; checkpoint —
 * subject set FOR UPDATE, затем work CAS до domain progress (bp-sql-heartbeat). Finalize —
 * WorkFinalizationRepository под тем же полным prefix.
 */
final readonly class WorkStoreRepository implements LeaseKeeper
{
    /** Snapshot изменился между pre-read и locks: столько раз перечитываем, затем доставка уступает. */
    private const int MAX_RELOADS = 3;

    private const array TERMINAL = [WorkState::Succeeded, WorkState::FailedItem, WorkState::Cancelled, WorkState::Superseded];

    public function __construct(
        private ProcessPayloadCodec $codec,
        private TransitionRepository $transitions,
        private AuthorityRepository $authority,
        private OutboxRepository $outbox,
        private IncidentRepository $incidents,
        private ProcessPolicy $policy,
        private ProcessTransaction $transaction,
        private SubjectLockResolver $resolver,
        private StageRegistry $registry,
    ) {}

    /**
     * Autocommit heartbeat CAS (bp-sql-heartbeat): только id+generation+token+running и живой DB
     * lease; ни SELECT, ни других locks. 0 rows — Lost: истёкший или отозванный lease не оживляется.
     * Attempt last_heartbeat_at — диагностика после успешного CAS, не authority.
     */
    public function heartbeat(ExecutionLease $lease): LeaseRenewal
    {
        try {
            $row = LedgerRow::one(
                "UPDATE prc_process_work SET lease_until = clock_timestamp() + make_interval(secs => ?), heartbeat_at = clock_timestamp()
                 WHERE id = ? AND generation = ? AND owner_token = ? AND state = 'running' AND lease_until > clock_timestamp()
                 RETURNING lease_until::text AS lease_until",
                [$this->policy->leaseSeconds($lease->stage), $lease->workId->value->value, $lease->generation, $lease->token->value],
            );
            if ($row === null) {
                return LeaseRenewal::lost();
            }
            DB::update('UPDATE prc_process_attempts SET last_heartbeat_at = clock_timestamp() WHERE id = ? AND finished_at IS NULL', [$lease->attemptId->value]);

            return LeaseRenewal::renewed(new DbInstant(new \DateTimeImmutable($row->string('lease_until'))));
        } catch (QueryException $exception) {
            throw DatabaseFailureClassifier::isTransient($exception) ? new DependencyUnavailable('database', $exception) : $exception;
        }
    }

    /**
     * Guarded progress живого owner (bp-sql-heartbeat): subject set FOR UPDATE → work под lock,
     * merged envelope (новый stage progress + текущий locked provider recovery plan), CAS с
     * expected checkpoint_seq и живым lease ДО domain progress. Любой guard failure откатывает все
     * записи; checkpoint не меняет admission group и не освобождает storage.
     */
    public function checkpoint(ExecutionLease $lease, StageCheckpoint $checkpoint, int $expectedSeq): CheckpointResult
    {
        $lease->guardProgress($checkpoint);
        try {
            for ($reload = 0; $reload < self::MAX_RELOADS; $reload++) {
                $snapshot = $this->authority->readAuthoritySnapshot($lease->workId);
                if ($snapshot instanceof AuthorityGap) {
                    return CheckpointResult::refused(CheckpointStatus::LostOwnership);
                }
                $result = $this->transaction->run(fn (): CheckpointResult|PrefixFailure => $this->checkpointLocked($snapshot, $lease, $checkpoint, $expectedSeq));
                if ($result instanceof CheckpointResult) {
                    return $result;
                }
            }
        } catch (QueryException $exception) {
            throw DatabaseFailureClassifier::isTransient($exception) ? new DependencyUnavailable('database', $exception) : $exception;
        }

        // Subject heads менялись на каждой попытке: progress не сохранён, owner уступает.
        return CheckpointResult::refused(CheckpointStatus::InputSuperseded);
    }

    /**
     * admitted → running для конкретной delivery (bp-sql-claim): generation+1, случайный token,
     * immutable attempt с executing handler version и DB lease. Stale/duplicate/expired delivery,
     * pause, handler hold и CapacityWait — ACK без handler; invariant mismatch откатывается и
     * открывает incident отдельной tx без молчаливого repair.
     */
    public function claim(WorkId $id, DeliveryGeneration $delivery): ClaimResult
    {
        $ids = [
            'token' => self::uuid(), 'attempt' => self::uuid(), 'event' => self::uuid(), 'projection' => self::uuid(),
            'wake' => self::uuid(), 'incident' => self::uuid(), 'incident_event' => self::uuid(), 'incident_projection' => self::uuid(),
        ];
        for ($reload = 0; $reload < self::MAX_RELOADS; $reload++) {
            $snapshot = $this->authority->readAuthoritySnapshot($id);
            if ($snapshot instanceof AuthorityGap) {
                return ClaimResult::refused(in_array($snapshot, [AuthorityGap::HandlerUnregistered, AuthorityGap::HandlerAuthorityMissing], true)
                    ? ClaimStatus::HandlerHeld
                    : ClaimStatus::InvariantViolation);
            }
            $corrupt = false;
            $result = $this->transaction->run(function () use ($snapshot, $delivery, $ids, &$corrupt): ClaimResult|PrefixFailure {
                return $this->claimLocked($snapshot, $delivery, $ids, $corrupt);
            });
            if ($result instanceof ClaimResult) {
                if ($corrupt) {
                    $this->openMembershipIncident($snapshot, $delivery, $ids);
                }

                return $result;
            }
        }

        // Состав authority менялся на каждой попытке: доставка уступает, durable admitted work
        // повторно доставит expired-claim recovery.
        return ClaimResult::refused(ClaimStatus::StaleDelivery);
    }

    /**
     * Fenced supersede stale input под уже взятым полным prefix (execution#s08): generation+1,
     * token/lease/claim/due/checkpoint очищены, вся admission group освобождена, transition.
     * Running owner (finalize при сменившемся input) закрывает свой attempt superseded: старый
     * result не публикуется.
     */
    public function supersedeLocked(AuthoritySnapshot $snapshot, LockedPrefix $prefix, ProcessUuid $eventId, ProcessUuid $projectionMessageId, ActorKind $actor): void
    {
        CallerTransaction::require('Work supersede');
        $previous = $prefix->work->string('state');
        $row = LedgerRow::one(
            "UPDATE prc_process_work
             SET state = 'superseded', reason_code = 'input_invalidated', generation = generation + 1, owner_token = NULL,
                 lease_until = NULL, dispatch_claim_until = NULL, due_at = NULL, provider_recovery_due_at = NULL,
                 checkpoint = NULL, checkpoint_schema_version = NULL, transition_seq = transition_seq + 1, updated_at = clock_timestamp()
             WHERE id = ? AND generation = ? AND state IN ('ready', 'deferred', 'admitted', 'running')
             RETURNING generation, transition_seq",
            [$snapshot->workId->value->value, $prefix->work->int('generation')],
        ) ?? throw new \LogicException('Supersede fence did not match the locked work.');
        if ($previous === WorkState::Running->value) {
            $closed = DB::update(
                "UPDATE prc_process_attempts SET finished_at = clock_timestamp(), terminal_kind = 'superseded'
                 WHERE work_id = ? AND generation = ? AND finished_at IS NULL",
                [$snapshot->workId->value->value, $prefix->work->int('generation')],
            );
            if ($closed !== 1) {
                throw new \LogicException('Running supersede must close exactly the current attempt.');
            }
        }
        if ($prefix->group !== null) {
            $this->releaseGroupLocked($prefix->group, count($prefix->members), 'superseded');
        }
        $this->transitions->recordWork(
            $eventId, $projectionMessageId, $snapshot->workId->value, $row->int('transition_seq'),
            $previous, WorkState::Superseded->value, 'input_invalidated', $actor, $row->int('generation'),
        );
    }

    /** Release всей group одной tx: DELETE всех members и released с ожидаемой version. */
    public function releaseGroupLocked(LedgerRow $group, int $expectedMembers, string $reason): void
    {
        CallerTransaction::require('Admission group release');
        $deleted = DB::delete('DELETE FROM prc_process_reservations WHERE admission_group_id = ?', [$group->string('id')]);
        $released = DB::update(
            "UPDATE prc_admission_groups SET state = 'released', version = version + 1, released_at = clock_timestamp(), release_reason = ?
             WHERE id = ? AND version = ? AND state <> 'released'",
            [$reason, $group->string('id'), $group->int('version')],
        );
        if ($deleted !== $expectedMembers || $released !== 1) {
            throw new \LogicException('Admission group release did not match its locked membership.');
        }
    }

    public function storeIntent(
        ProcessUuid $id,
        ContentHash $intentKey,
        StageCode $stage,
        SubjectKey $subject,
        ContentHash $semanticPolicyHash,
        ContentHash $accessPolicyHash,
        int $cycleSeq,
        int $expectedHeadGeneration,
        IntentState $state,
        ?\DateTimeImmutable $nextDueAt = null,
    ): void {
        CallerTransaction::require('Operation intent insert');
        $ownerSubject = SchemaSupportedStages::subject($stage);
        if ($ownerSubject !== $subject->kind) {
            throw new \InvalidArgumentException('Intent subject does not belong to the stage owner table.');
        }
        DB::insert(
            "INSERT INTO prc_operation_intents
               (id, intent_key, stage, subject_kind, subject_id, semantic_policy_hash, access_policy_hash,
                cycle_seq, expected_head_generation, state, requested_at, next_due_at)
             VALUES (?, decode(?, 'hex'), ?, ?, ?, decode(?, 'hex'), decode(?, 'hex'), ?, ?, ?, clock_timestamp(), ?)",
            [
                $id->value, $intentKey->hex, $stage->value, $subject->kind->value,
                is_int($subject->id) ? (string) $subject->id : $subject->id->value,
                $semanticPolicyHash->hex, $accessPolicyHash->hex, $cycleSeq, $expectedHeadGeneration, $state->value,
                $nextDueAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP'),
            ],
        );
    }

    /** Новый work всегда ready без due_at; transition 1 и projection intent в той же tx. */
    public function storeWork(
        WorkId $id,
        WorkInput $input,
        int $inputRevision,
        ?ProcessUuid $intentId,
        string $cohortKey,
        ?int $platformId,
        ?ProcessUuid $originRunId,
        int $priority,
        ProcessUuid $eventId,
        ProcessUuid $projectionMessageId,
    ): void {
        CallerTransaction::require('Work insert');
        $stage = $input->stage();
        $ownerColumn = SchemaSupportedStages::ownerColumn($stage);
        $payload = $this->codec->encodeInput($input);
        if ($payload->json === null || $payload->schemaVersion === null) {
            throw new \LogicException('Work input codec returned an absent payload.');
        }
        /** @var array<string, int|string> $fields */
        $fields = json_decode($payload->json, true, flags: JSON_THROW_ON_ERROR);
        DB::insert(
            "INSERT INTO prc_process_work
               (id, stage, work_key, work_identity, work_identity_schema_version, intent_id, input_revision, input_hash,
                input_payload, input_schema_version, state, priority, platform_id, cohort_key, origin_run_id,
                {$ownerColumn}, transition_seq, created_at, updated_at)
             VALUES (?, ?, decode(?, 'hex'), ?::jsonb, ?, ?, ?, decode(?, 'hex'), ?::jsonb, ?, ?, ?, ?, ?, ?, ?, 1,
                     clock_timestamp(), clock_timestamp())",
            [
                $id->value->value, $stage->value, (new WorkIdentity($input))->key()->hex, $payload->json, $payload->schemaVersion,
                $intentId?->value, $inputRevision, self::inputHash($payload->json)->hex, $payload->json, $payload->schemaVersion,
                WorkState::Ready->value, $priority, $platformId, $cohortKey, $originRunId?->value, $fields[$ownerColumn],
            ],
        );
        $this->transitions->recordWork($eventId, $projectionMessageId, $id->value, 1, null, WorkState::Ready->value, null, ActorKind::System, 0);
    }

    public function linkRun(ProcessUuid $runId, WorkId $workId, RunWorkRelation $relation, AccountingRole $role): void
    {
        CallerTransaction::require('Run work link insert');
        DB::insert(
            'INSERT INTO prc_process_run_work (run_id, work_id, relation, accounting_role) VALUES (?, ?, ?, ?)',
            [$runId->value, $workId->value->value, $relation->value, $role->value],
        );
    }

    /** Immutable input fingerprint: тот же canonical payload, отдельный domain separator от work_key. */
    public static function inputHash(string $canonicalInput): ContentHash
    {
        return ContentHash::of('work-input:v1:'.$canonicalInput);
    }

    /** @param array<string, ProcessUuid> $ids */
    private function claimLocked(AuthoritySnapshot $snapshot, DeliveryGeneration $delivery, array $ids, bool &$corrupt): ClaimResult|PrefixFailure
    {
        $prefix = $this->authority->lockPrefix($snapshot, skipLockedWork: false);
        if ($prefix === PrefixFailure::WorkMissing) {
            return ClaimResult::refused(ClaimStatus::InvariantViolation);
        }
        if ($prefix instanceof PrefixFailure) {
            return $prefix;
        }
        $work = $prefix->work;
        $state = WorkState::from($work->string('state'));
        $current = $work->int('dispatch_generation');
        $refusal = match (true) {
            in_array($state, self::TERMINAL, true) => ClaimStatus::WorkTerminal,
            $state === WorkState::Running => $current === $delivery->value ? ClaimStatus::DuplicateDelivery : ClaimStatus::StaleDelivery,
            $state !== WorkState::Admitted, $current !== $delivery->value => ClaimStatus::StaleDelivery,
            $work->bool('claim_expired') => ClaimStatus::ClaimExpired,
            default => null,
        };
        if ($refusal !== null) {
            return ClaimResult::refused($refusal);
        }
        // Invalidation тоже освобождает group: сначала проверяем весь набор, не чиня corruption.
        if (! $this->authority->exactMembership($snapshot, $prefix, 'admitted')) {
            $corrupt = true;

            return ClaimResult::refused(ClaimStatus::InvariantViolation);
        }
        if (! $prefix->intentActive || $this->resolver->inputInvalidated($snapshot->input, $snapshot->inputRevision, $prefix->subjects)) {
            $this->supersedeLocked($snapshot, $prefix, $ids['event'], $ids['projection'], ActorKind::Worker);

            return ClaimResult::refused(ClaimStatus::WorkTerminal);
        }
        // Пока work admitted, pause/hold не меняют group здесь: reconciler переводит её в blocked.
        if (! $prefix->controlsEnabled) {
            return ClaimResult::refused(ClaimStatus::Paused);
        }
        if (! $prefix->handlerHealthy) {
            return ClaimResult::refused(ClaimStatus::HandlerHeld);
        }
        foreach ($prefix->lanes as $lane) {
            if ($lane->running >= $lane->maxRunning) {
                $this->waitForCapacityLocked($snapshot, $prefix, $ids);

                return ClaimResult::refused(ClaimStatus::CapacityWait);
            }
        }

        return ClaimResult::claimed($this->grantLocked($snapshot, $prefix, $ids));
    }

    /** @param array<string, ProcessUuid> $ids */
    private function grantLocked(AuthoritySnapshot $snapshot, LockedPrefix $prefix, array $ids): ExecutionLease
    {
        $workId = $snapshot->workId->value->value;
        $dispatchGeneration = $prefix->work->int('dispatch_generation');
        $row = LedgerRow::one(
            "UPDATE prc_process_work
             SET state = 'running', generation = generation + 1, owner_token = ?,
                 lease_until = clock_timestamp() + make_interval(secs => ?), heartbeat_at = clock_timestamp(),
                 dispatch_claim_until = NULL, execution_attempts = execution_attempts + 1,
                 transition_seq = transition_seq + 1, updated_at = clock_timestamp()
             WHERE id = ? AND state = 'admitted' AND dispatch_generation = ?
             RETURNING generation, lease_until::text AS lease_until, transition_seq",
            [$ids['token']->value, $this->policy->leaseSeconds($snapshot->handler->stage), $workId, $dispatchGeneration],
        ) ?? throw new \LogicException('Claim fence did not match the locked admitted work.');
        $generation = $row->int('generation');
        DB::insert(
            "INSERT INTO prc_process_attempts
               (id, work_id, generation, owner_token_fingerprint, handler_version, started_at, worker_host, worker_pid)
             VALUES (?, ?, ?, decode(?, 'hex'), ?, clock_timestamp(), ?, ?)",
            [$ids['attempt']->value, $workId, $generation, hash('sha256', $ids['token']->value), $snapshot->handler->handlerVersion, gethostname() ?: 'unknown', getmypid() ?: 0],
        );
        $group = $prefix->group ?? throw new \LogicException('Claim requires the exact admission group.');
        $groupUpdated = DB::update(
            "UPDATE prc_admission_groups SET state = 'running', attempt_id = ?, version = version + 1
             WHERE id = ? AND state = 'admitted' AND dispatch_generation = ? AND version = ?",
            [$ids['attempt']->value, $group->string('id'), $dispatchGeneration, $group->int('version')],
        );
        $membersUpdated = DB::update(
            "UPDATE prc_process_reservations SET state = 'running' WHERE admission_group_id = ? AND state = 'admitted'",
            [$group->string('id')],
        );
        if ($groupUpdated !== 1 || $membersUpdated !== count($prefix->members)) {
            throw new \LogicException('Claim must move the whole admission group to running.');
        }
        $this->transitions->recordWork(
            $ids['event'], $ids['projection'], $snapshot->workId->value, $row->int('transition_seq'),
            WorkState::Admitted->value, WorkState::Running->value, null, ActorKind::Worker, $generation,
        );

        return new ExecutionLease(
            workId: $snapshot->workId,
            attemptId: $ids['attempt'],
            generation: $generation,
            token: $ids['token'],
            leaseUntil: new \DateTimeImmutable($row->string('lease_until')),
            inputRevision: $snapshot->inputRevision,
            inputHash: $snapshot->inputHash,
            checkpointSeq: $prefix->work->int('checkpoint_seq'),
            checkpoint: $this->lockedCheckpoint($prefix->work),
            stage: $snapshot->handler->stage,
            handlerVersion: $snapshot->handler->handlerVersion,
        );
    }

    /** Decoded envelope locked work row через stage codecs registry; NULL pair — без checkpoint. */
    public function lockedCheckpoint(LedgerRow $work): ?WorkCheckpointEnvelope
    {
        $checkpoint = $work->nullableString('checkpoint');

        return $this->registry->payloadCodec()->decodeCheckpoint(
            new VersionedPayload($checkpoint, $checkpoint === null ? null : $work->int('checkpoint_schema_version')),
        );
    }

    /** Merged envelope: новый stage progress сохраняет текущий locked provider recovery plan. */
    public static function mergeProgress(?WorkCheckpointEnvelope $current, StageCheckpoint $progress): WorkCheckpointEnvelope
    {
        return $current === null ? new WorkCheckpointEnvelope($progress, new NoProviderRecovery) : $current->withProgress($progress);
    }

    private function checkpointLocked(AuthoritySnapshot $snapshot, ExecutionLease $lease, StageCheckpoint $progress, int $expectedSeq): CheckpointResult|PrefixFailure
    {
        $subjects = $this->authority->lockSubjects($snapshot);
        if ($subjects instanceof PrefixFailure) {
            return $subjects;
        }
        $workId = $lease->workId->value->value;
        $work = LedgerRow::one(
            'SELECT generation, owner_token, state, intent_id, checkpoint_seq, checkpoint::text AS checkpoint, checkpoint_schema_version,
                    COALESCE(lease_until > clock_timestamp(), false) AS live
             FROM prc_process_work WHERE id = ? FOR UPDATE',
            [$workId],
        );
        if ($work === null || $work->int('generation') !== $lease->generation || $work->nullableString('owner_token') !== $lease->token->value
            || $work->string('state') !== WorkState::Running->value || ! $work->bool('live')) {
            return CheckpointResult::refused(CheckpointStatus::LostOwnership);
        }
        $intentId = $work->nullableString('intent_id');
        $intentActive = $intentId === null || LedgerRow::one('SELECT state FROM prc_operation_intents WHERE id = ?', [$intentId])?->string('state') === IntentState::Active->value;
        if (! $intentActive || $this->resolver->inputInvalidated($snapshot->input, $snapshot->inputRevision, $subjects)) {
            return CheckpointResult::refused(CheckpointStatus::InputSuperseded);
        }
        if ($work->int('checkpoint_seq') !== $expectedSeq) {
            return CheckpointResult::refused(CheckpointStatus::StaleCheckpoint);
        }
        $merged = self::mergeProgress($this->lockedCheckpoint($work), $progress);
        $payload = $this->registry->payloadCodec()->encodeCheckpoint($merged);
        $row = LedgerRow::one(
            "UPDATE prc_process_work
             SET checkpoint = ?::jsonb, checkpoint_schema_version = ?, checkpoint_seq = checkpoint_seq + 1,
                 lease_until = clock_timestamp() + make_interval(secs => ?), heartbeat_at = clock_timestamp(), updated_at = clock_timestamp()
             WHERE id = ? AND generation = ? AND owner_token = ? AND state = 'running'
               AND lease_until > clock_timestamp() AND checkpoint_seq = ?
             RETURNING checkpoint_seq, lease_until::text AS lease_until",
            [$payload->json, $payload->schemaVersion, $this->policy->leaseSeconds($lease->stage), $workId, $lease->generation, $lease->token->value, $expectedSeq],
        );
        if ($row === null) {
            // DB clock может пересечь expiry после live precheck, пока codec строит envelope.
            // До CAS не было writes; domain progress/attempt ниже не выполняются.
            return CheckpointResult::refused(CheckpointStatus::LostOwnership);
        }
        // CAS изменил ровно одну строку: только теперь domain progress; его ошибка откатывает и CAS.
        $this->registry->resultRepository($lease->stage)->storeProgress($snapshot->input, $progress);
        DB::update('UPDATE prc_process_attempts SET last_checkpoint_at = clock_timestamp() WHERE id = ? AND finished_at IS NULL', [$lease->attemptId->value]);

        return CheckpointResult::saved($row->int('checkpoint_seq'), new DbInstant(new \DateTimeImmutable($row->string('lease_until'))), $merged);
    }

    /**
     * CapacityWait: вся group сохраняется; новый dispatch generation и delivery deadline, обычный
     * wake через 5–10 s. Старые deliveries становятся stale; item budget, sleep и release нет.
     *
     * @param  array<string, ProcessUuid>  $ids
     */
    private function waitForCapacityLocked(AuthoritySnapshot $snapshot, LockedPrefix $prefix, array $ids): void
    {
        $stage = $snapshot->handler->stage;
        $previous = $prefix->work->int('dispatch_generation');
        $row = LedgerRow::one(
            "UPDATE prc_process_work
             SET dispatch_generation = dispatch_generation + 1, dispatch_claim_until = clock_timestamp() + make_interval(secs => ?),
                 transition_seq = transition_seq + 1, updated_at = clock_timestamp()
             WHERE id = ? AND state = 'admitted' AND dispatch_generation = ?
             RETURNING dispatch_generation, transition_seq",
            [$this->policy->claimVisibilitySeconds($stage), $snapshot->workId->value->value, $previous],
        ) ?? throw new \LogicException('CapacityWait fence did not match the locked admitted work.');
        $group = $prefix->group ?? throw new \LogicException('CapacityWait keeps the exact admission group.');
        $updated = DB::update(
            "UPDATE prc_admission_groups SET dispatch_generation = ?, version = version + 1
             WHERE id = ? AND state = 'admitted' AND dispatch_generation = ? AND version = ?",
            [$row->int('dispatch_generation'), $group->string('id'), $previous, $group->int('version')],
        );
        if ($updated !== 1) {
            throw new \LogicException('CapacityWait must move the whole admission group to the new delivery generation.');
        }
        $this->transitions->recordWork(
            $ids['event'], $ids['projection'], $snapshot->workId->value, $row->int('transition_seq'),
            WorkState::Admitted->value, WorkState::Admitted->value, DeferralReason::CapacityWait->value, ActorKind::Worker, $prefix->work->int('generation'),
        );
        $delayMs = random_int($this->policy->capacityWaitMinMs, $this->policy->capacityWaitMaxMs);
        $this->outbox->enqueueWorkWake($ids['wake'], $snapshot->workId, new DeliveryGeneration($row->int('dispatch_generation')), $stage, $delayMs / 1000);
    }

    /** @param array<string, ProcessUuid> $ids */
    private function openMembershipIncident(AuthoritySnapshot $snapshot, DeliveryGeneration $delivery, array $ids): void
    {
        $parentLane = $this->policy->parentLane($snapshot->handler->stage);
        $this->transaction->run(fn (): ProcessUuid => $this->incidents->open(
            fingerprint: ContentHash::of('admission-membership:v1:'.$snapshot->workId->value->value),
            scopeKey: 'lane:'.$parentLane,
            kind: 'admission_membership_mismatch',
            evidence: ['work_id' => $snapshot->workId->value->value, 'dispatch_generation' => $delivery->value],
            incidentId: $ids['incident'],
            eventId: $ids['incident_event'],
            projectionMessageId: $ids['incident_projection'],
        ));
    }

    private static function uuid(): ProcessUuid
    {
        return new ProcessUuid(Str::lower((string) Str::uuid()));
    }
}
