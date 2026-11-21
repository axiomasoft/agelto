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
use App\Dto\Procurement\Process\ExtractionWindowInput;
use App\Dto\Procurement\Process\LeaseRenewal;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\ProviderRecoveryPlan;
use App\Dto\Procurement\Process\StageCheckpoint;
use App\Dto\Procurement\Process\SubjectKey;
use App\Dto\Procurement\Process\WorkCheckpointEnvelope;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkIdentity;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Procurement\Process\AccountingRole;
use App\Enums\Procurement\Process\ActorKind;
use App\Enums\Procurement\Process\AuthorityGap;
use App\Enums\Procurement\Process\CheckpointStatus;
use App\Enums\Procurement\Process\ClaimStatus;
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

/**
 * Writer intents, новых ready work, admitted→running claim и ownership живого attempt.
 */
final readonly class WorkStoreRepository implements LeaseKeeper
{
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
        private ?WorkStoreSqlHelper $helper = null,
    ) {}

    public function pinProviderOperationLocked(ExecutionLease $lease, ProviderRecoveryPlan $plan): void
    {
        $this->helper()->pinProviderOperationLocked($lease, $plan);
    }

    public function consumeProviderRetryLocked(WorkId $workId, LedgerRow $work, ProcessUuid $physical): void
    {
        $this->helper()->consumeProviderRetryLocked($workId, $work, $physical);
    }

    public function heartbeat(ExecutionLease $lease): LeaseRenewal
    {
        try {
            return $this->transaction->run(function () use ($lease): LeaseRenewal {
                $row = LedgerRow::one(
                    "UPDATE prc_process_work SET lease_until = clock_timestamp() + make_interval(secs => ?), heartbeat_at = clock_timestamp()
                     WHERE id = ? AND generation = ? AND owner_token = ? AND state = 'running' AND lease_until > clock_timestamp()
                     RETURNING lease_until::text AS lease_until",
                    [$this->policy->leaseSeconds($lease->stage), $lease->workId->value->value, $lease->generation, $lease->token->value],
                );
                if ($row === null) {
                    return LeaseRenewal::lost();
                }
                $updated = DB::update(
                    'UPDATE prc_process_attempts SET last_heartbeat_at = clock_timestamp() WHERE id = ? AND finished_at IS NULL',
                    [$lease->attemptId->value],
                );
                if ($updated === 0) {
                    // Не QueryException: closure не ретраится, а исключение откатывает продление lease.
                    throw new AttemptHeartbeatClosed;
                }

                return LeaseRenewal::renewed(new DbInstant(new \DateTimeImmutable($row->string('lease_until'))));
            });
        } catch (AttemptHeartbeatClosed) {
            return LeaseRenewal::lost();
        } catch (QueryException $exception) {
            throw DatabaseFailureClassifier::isTransient($exception) ? new DependencyUnavailable('database', $exception) : $exception;
        }
    }

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
        } catch (AttemptCheckpointClosed) {
            return CheckpointResult::refused(CheckpointStatus::LostOwnership);
        } catch (QueryException $exception) {
            throw DatabaseFailureClassifier::isTransient($exception) ? new DependencyUnavailable('database', $exception) : $exception;
        }

        return CheckpointResult::refused(CheckpointStatus::InputSuperseded);
    }

    public function claim(WorkId $id, DeliveryGeneration $delivery): ClaimResult
    {
        $ids = [
            'token' => ProcessUuid::random(), 'attempt' => ProcessUuid::random(), 'event' => ProcessUuid::random(), 'projection' => ProcessUuid::random(),
            'wake' => ProcessUuid::random(), 'incident' => ProcessUuid::random(), 'incident_event' => ProcessUuid::random(), 'incident_projection' => ProcessUuid::random(),
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
                    $this->transaction->run(fn () => $this->incidents->openMembershipIncident(
                        $snapshot->workId, 'lane:'.$this->policy->parentLane($snapshot->handler->stage), 'claim',
                        $ids['incident'], $ids['incident_event'], $ids['incident_projection'],
                    ));
                }

                return $result;
            }
        }

        return ClaimResult::refused(ClaimStatus::StaleDelivery);
    }

    public function supersedeLocked(AuthoritySnapshot $snapshot, LockedPrefix $prefix, ProcessUuid $eventId, ProcessUuid $projectionMessageId, ActorKind $actor): void
    {
        CallerTransaction::require('Work supersede');
        $previous = $prefix->work->string('state');
        $row = LedgerRow::one(
            "UPDATE prc_process_work
             SET state = 'superseded', reason_code = 'input_invalidated', generation = generation + 1, owner_token = NULL,
                 lease_until = NULL, dispatch_claim_until = NULL, due_at = NULL, provider_recovery_due_at = NULL,
                 checkpoint = NULL, checkpoint_schema_version = NULL, transition_seq = transition_seq + 1, updated_at = clock_timestamp()
             WHERE id = ? AND generation = ? AND state IN ('ready', 'deferred', 'admitted', 'running', 'blocked')
             RETURNING generation, transition_seq",
            [$snapshot->workId->value->value, $prefix->work->int('generation')],
        ) ?? throw new \LogicException('Supersede fence did not match the locked work.');
        if ($previous === WorkState::Running->value) {
            if (DB::update("UPDATE prc_process_attempts SET finished_at = clock_timestamp(), terminal_kind = 'superseded' WHERE work_id = ? AND generation = ? AND finished_at IS NULL", [$snapshot->workId->value->value, $prefix->work->int('generation')]) !== 1) {
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
        $this->helper()->carrySupersededRequirement($snapshot, $prefix, $eventId, $this->storeWork(...));
    }

    public function carryRunLinks(WorkId $from, WorkId $to): void
    {
        $this->helper()->carryRunLinks($from, $to);
    }

    /** @return list<LedgerRow> */
    public function lockRunLinks(WorkId $workId): array
    {
        return $this->helper()->lockRunLinks($workId);
    }

    public function upsertDiscoveredRunLink(string $runId, WorkId $workId, string $role): void
    {
        $this->helper()->upsertDiscoveredRunLink($runId, $workId, $role);
    }

    public function releaseGroupLocked(LedgerRow $group, int $expectedMembers, string $reason): void
    {
        $this->helper()->releaseGroupLocked($group, $expectedMembers, $reason);
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
        ?WorkIdentity $identity = null,
    ): void {
        CallerTransaction::require('Work insert');
        $stage = $input->stage();
        $ownerColumn = SchemaSupportedStages::ownerColumn($stage);
        $payload = $this->codec->encodeInput($input);
        $identity ??= new WorkIdentity($input);
        if ($identity->input != $input || ($identity->manualRetryIntentId !== null && $identity->manualRetryIntentId != $intentId)) {
            throw new \InvalidArgumentException('Work identity must match its immutable input and manual intent.');
        }
        $identityPayload = $identity->payload();
        if ($payload->json === null || $payload->schemaVersion === null) {
            throw new \LogicException('Work input codec returned an absent payload.');
        }
        /** @var array<string, int|string> $fields */
        $fields = json_decode($payload->json, true, flags: JSON_THROW_ON_ERROR);
        $ownerId = $fields[$ownerColumn] ?? null;
        if ($input instanceof ExtractionWindowInput) {
            $ownerId = LedgerRow::one('SELECT id FROM prc_analysis_windows WHERE run_id=? AND window_key=?', [$input->analysisRunId->value, $input->windowKey])?->string('id');
        }
        $ownerId ??= throw new \InvalidArgumentException('Work input has no resolved owner: '.$ownerColumn);
        DB::insert(
            "INSERT INTO prc_process_work
               (id, stage, work_key, work_identity, work_identity_schema_version, intent_id, input_revision, input_hash,
                input_payload, input_schema_version, state, priority, platform_id, cohort_key, origin_run_id,
                {$ownerColumn}, transition_seq, created_at, updated_at)
             VALUES (?, ?, decode(?, 'hex'), ?::jsonb, ?, ?, ?, decode(?, 'hex'), ?::jsonb, ?, ?, ?, ?, ?, ?, ?, 1,
                     clock_timestamp(), clock_timestamp())",
            [
                $id->value->value, $stage->value, $identity->key()->hex, $identityPayload->json, $identityPayload->schemaVersion,
                $intentId?->value, $inputRevision, self::inputHash($payload->json)->hex, $payload->json, $payload->schemaVersion,
                WorkState::Ready->value, $priority, $platformId, $cohortKey, $originRunId?->value, $ownerId,
            ],
        );
        $this->transitions->recordWork($eventId, $projectionMessageId, $id->value, 1, null, WorkState::Ready->value, null, ActorKind::System, 0);
    }

    public function linkRun(ProcessUuid $runId, WorkId $workId, RunWorkRelation $relation, AccountingRole $role): void
    {
        $this->helper()->linkRun($runId, $workId, $relation, $role);
    }

    public static function inputHash(string $canonicalInput): ContentHash
    {
        return ContentHash::of('work-input:v1:'.$canonicalInput);
    }

    public function lockedCheckpoint(LedgerRow $work): ?WorkCheckpointEnvelope
    {
        return $this->helper()->lockedCheckpoint($work);
    }

    public static function mergeProgress(?WorkCheckpointEnvelope $current, StageCheckpoint $progress): WorkCheckpointEnvelope
    {
        return WorkStoreSqlHelper::mergeProgress($current, $progress);
    }

    /**
     * @param  array<string, ProcessUuid>  $ids
     *
     * @param-out bool $corrupt
     */
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
        if (! $this->authority->exactMembership($snapshot, $prefix, 'admitted')) {
            $corrupt = true;

            return ClaimResult::refused(ClaimStatus::InvariantViolation);
        }
        if (! $prefix->intentActive || $this->resolver->inputInvalidated($snapshot->input, $snapshot->inputRevision, $prefix->subjects)) {
            $this->supersedeLocked($snapshot, $prefix, $ids['event'], $ids['projection'], ActorKind::Worker);
            $this->outbox->enqueueFeederWake($ids['wake'], $this->policy->parentLane($snapshot->handler->stage));

            return ClaimResult::refused(ClaimStatus::WorkTerminal);
        }
        if (! $prefix->controlsEnabled) {
            return ClaimResult::refused(ClaimStatus::Paused);
        }
        if (! $prefix->handlerHealthy) {
            return ClaimResult::refused(ClaimStatus::HandlerHeld);
        }
        foreach ($prefix->lanes as $lane) {
            if ($lane->running >= $lane->maxRunning) {
                $this->helper()->waitForCapacityLocked($snapshot, $prefix, $ids);

                return ClaimResult::refused(ClaimStatus::CapacityWait);
            }
        }

        return ClaimResult::claimed($this->helper()->grantLocked($snapshot, $prefix, $ids));
    }

    private function checkpointLocked(AuthoritySnapshot $snapshot, ExecutionLease $lease, StageCheckpoint $progress, int $expectedSeq): CheckpointResult|PrefixFailure
    {
        $this->authority->lockCheckpointStorage($snapshot);
        $subjects = $this->authority->lockSubjects($snapshot);
        if ($subjects instanceof PrefixFailure) {
            return $subjects;
        }
        $work = LedgerRow::one(
            'SELECT generation, owner_token, state, intent_id, checkpoint_seq, checkpoint::text AS checkpoint, checkpoint_schema_version,
                    COALESCE(lease_until > clock_timestamp(), false) AS live
             FROM prc_process_work WHERE id = ? FOR UPDATE',
            [$lease->workId->value->value],
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
        if (! $this->authority->sameAnalysisStorage($snapshot)) {
            return PrefixFailure::SnapshotChanged;
        }

        return $this->helper()->persistCheckpointCas($snapshot, $lease, $work, $progress, $expectedSeq);
    }

    private function helper(): WorkStoreSqlHelper
    {
        return $this->helper ?? new WorkStoreSqlHelper($this->transitions, $this->outbox, $this->policy, $this->resolver, $this->registry);
    }
}

/** Откат heartbeat: attempt уже закрыт. Не QueryException, поэтому ProcessTransaction не повторяет closure. */
final class AttemptHeartbeatClosed extends \RuntimeException {}

/** Откат checkpoint: attempt уже закрыт. Не QueryException, поэтому ProcessTransaction не повторяет closure. */
final class AttemptCheckpointClosed extends \RuntimeException {}
