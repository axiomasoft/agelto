<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Dto\Procurement\Process\AuthoritySnapshot;
use App\Dto\Procurement\Process\CheckpointResult;
use App\Dto\Procurement\Process\ConsumesProviderOperation;
use App\Dto\Procurement\Process\DbInstant;
use App\Dto\Procurement\Process\DeliveryGeneration;
use App\Dto\Procurement\Process\DetailInput;
use App\Dto\Procurement\Process\ExecutionLease;
use App\Dto\Procurement\Process\NoProviderRecovery;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\ProviderRecoveryPlan;
use App\Dto\Procurement\Process\StageCheckpoint;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\Process\WorkCheckpointEnvelope;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkIdentity;
use App\Enums\Procurement\Process\AccountingRole;
use App\Enums\Procurement\Process\ActorKind;
use App\Enums\Procurement\Process\CheckpointStatus;
use App\Enums\Procurement\Process\DeferralReason;
use App\Enums\Procurement\Process\IntentState;
use App\Enums\Procurement\Process\ProviderRecoveryMode;
use App\Enums\Procurement\Process\RunWorkRelation;
use App\Enums\Procurement\Process\WorkState;
use App\Services\Procurement\Process\ProcessPolicy;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Process\SubjectLockResolver;
use Illuminate\Support\Facades\DB;

/**
 * SQL-хелперы WorkStoreRepository для provider checkpoint linkage, admission group transitions,
 * supersede successor carry и run-work связей.
 */
final readonly class WorkStoreSqlHelper
{
    public function __construct(
        private TransitionRepository $transitions,
        private OutboxRepository $outbox,
        private ProcessPolicy $policy,
        private SubjectLockResolver $resolver,
        private StageRegistry $registry,
    ) {}

    public function pinProviderOperationLocked(ExecutionLease $lease, ProviderRecoveryPlan $plan): void
    {
        CallerTransaction::require('Provider operation checkpoint linkage');
        $row = LedgerRow::one(
            "SELECT checkpoint::text AS checkpoint,checkpoint_schema_version,checkpoint_seq FROM prc_process_work
             WHERE id=? AND generation=? AND owner_token=? AND state='running'",
            [$lease->workId->value->value, $lease->generation, $lease->token->value],
        ) ?? throw new \LogicException('Provider operation owner revoked.');
        $current = $this->lockedCheckpoint($row);
        $envelope = new WorkCheckpointEnvelope($current->stageProgress ?? $this->registry->initialProgress($lease->stage), $plan);
        $payload = $this->registry->payloadCodec()->encodeCheckpoint($envelope);
        if (DB::update(
            "UPDATE prc_process_work SET checkpoint=?::jsonb,checkpoint_schema_version=?,checkpoint_seq=checkpoint_seq+1
             WHERE id=? AND generation=? AND owner_token=? AND state='running' AND checkpoint_seq=?",
            [$payload->json, $payload->schemaVersion, $lease->workId->value->value, $lease->generation, $lease->token->value, $row->int('checkpoint_seq')],
        ) !== 1) {
            throw new \LogicException('Provider operation checkpoint CAS failed.');
        }
    }

    public function consumeProviderRetryLocked(WorkId $workId, LedgerRow $work, ProcessUuid $physical): void
    {
        CallerTransaction::require('Provider retry directive consumption');
        $envelope = $this->lockedCheckpoint($work);
        $plan = $envelope?->providerRecoveryPlan;
        if (! $plan instanceof ProviderRecoveryPlan
            || $plan->physicalCallId != $physical || $plan->mode !== ProviderRecoveryMode::KnownRetryEligible) {
            throw new \LogicException('Retry requires the exact known-safe recovery directive.');
        }
        $payload = $this->registry->payloadCodec()->encodeCheckpoint($envelope->withoutProviderRecovery());
        if (DB::update(
            'UPDATE prc_process_work SET checkpoint=?::jsonb,checkpoint_schema_version=?,checkpoint_seq=checkpoint_seq+1 WHERE id=? AND checkpoint_seq=?',
            [$payload->json, $payload->schemaVersion, $workId->value->value, $work->int('checkpoint_seq')],
        ) !== 1) {
            throw new \LogicException('Provider retry checkpoint conflict.');
        }
    }

    /**
     * @param  \Closure(WorkId, DetailInput, int, ProcessUuid, string, ?int, ?ProcessUuid, int, ProcessUuid, ProcessUuid, WorkIdentity): void  $storeWork
     */
    public function carrySupersededRequirement(
        AuthoritySnapshot $snapshot,
        LockedPrefix $prefix,
        ProcessUuid $eventId,
        \Closure $storeWork,
    ): void {
        $input = $snapshot->input;
        if (! $input instanceof DetailInput) {
            return;
        }
        $owner = $prefix->subjects[$this->resolver->subjects($input)[0]->key()]
            ?? throw new \LogicException('Owner subject must be locked before supersede.');
        if ($owner->headGeneration === $input->expectedHeadGeneration) {
            return;
        }
        $intentId = new ProcessUuid($prefix->work->nullableString('intent_id') ?? $input->intentId->value);
        $intent = LedgerRow::one('SELECT state FROM prc_operation_intents WHERE id = ? FOR UPDATE', [$intentId->value]);
        if ($intent === null || $intent->string('state') === IntentState::Pending->value) {
            return;
        }
        $repinned = new DetailInput(
            $input->procurementId,
            $intentId,
            $owner->headGeneration,
            $input->semanticPolicyHash,
            $input->accessPolicyHash,
            $input->adapterVersion,
        );
        $identity = new WorkIdentity($repinned);
        $existing = LedgerRow::one(
            "SELECT id, work_identity = ?::jsonb AS same FROM prc_process_work WHERE work_key = decode(?, 'hex')",
            [$identity->payload()->json, $identity->key()->hex],
        );
        if ($existing !== null && ! $existing->bool('same')) {
            throw new \LogicException('Successor work_key collides with a different immutable identity.');
        }
        if ($existing !== null) {
            $successor = new WorkId(new ProcessUuid($existing->string('id')));
        } elseif ($intent->string('state') === IntentState::Active->value) {
            $successor = new WorkId(self::derivedId($eventId, 'superseded-successor:work'));
            $work = $prefix->work;
            $origin = $work->nullableString('origin_run_id');
            $storeWork(
                $successor,
                $repinned,
                $snapshot->inputRevision,
                $intentId,
                $work->string('cohort_key'),
                $work->nullableInt('platform_id'),
                $origin === null ? null : new ProcessUuid($origin),
                $work->int('priority'),
                self::derivedId($eventId, 'superseded-successor:event'),
                self::derivedId($eventId, 'superseded-successor:projection'),
                $identity,
            );
            DB::update(
                'UPDATE prc_operation_intents SET expected_head_generation = ? WHERE id = ?',
                [$owner->headGeneration, $intentId->value],
            );
        } else {
            return;
        }
        $this->carryRunLinks($snapshot->workId, $successor);
    }

    public function carryRunLinks(WorkId $from, WorkId $to): void
    {
        CallerTransaction::require('Run link carry');
        foreach ($this->lockRunLinks($from) as $link) {
            $required = $link->string('accounting_role') === AccountingRole::Required->value && ! $link->bool('cancelled');
            $this->upsertDiscoveredRunLink($link->string('run_id'), $to, $required ? AccountingRole::Required->value : AccountingRole::Observes->value);
            if ($required) {
                DB::update(
                    'UPDATE prc_process_run_work SET accounting_role = ? WHERE run_id = ? AND work_id = ?',
                    [AccountingRole::Observes->value, $link->string('run_id'), $from->value->value],
                );
            }
        }
    }

    /** @return list<LedgerRow> */
    public function lockRunLinks(WorkId $workId): array
    {
        CallerTransaction::require('Run link lock');

        return LedgerRow::all(
            'SELECT rw.run_id, rw.accounting_role, r.cancel_requested_at IS NOT NULL AS cancelled
             FROM prc_process_run_work rw JOIN prc_process_runs r ON r.id = rw.run_id
             WHERE rw.work_id = ? ORDER BY rw.run_id FOR UPDATE OF r',
            [$workId->value->value],
        );
    }

    public function upsertDiscoveredRunLink(string $runId, WorkId $workId, string $role): void
    {
        CallerTransaction::require('Run link upsert');
        DB::insert(
            "INSERT INTO prc_process_run_work (run_id, work_id, relation, accounting_role) VALUES (?, ?, ?, ?)
             ON CONFLICT (run_id, work_id) DO UPDATE SET accounting_role = CASE
               WHEN EXCLUDED.accounting_role = 'required' THEN 'required' ELSE prc_process_run_work.accounting_role END",
            [$runId, $workId->value->value, RunWorkRelation::Discovered->value, $role],
        );
    }

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

    public function linkRun(ProcessUuid $runId, WorkId $workId, RunWorkRelation $relation, AccountingRole $role): void
    {
        CallerTransaction::require('Run work link insert');
        if (LedgerRow::one('SELECT id FROM prc_process_work WHERE id = ? FOR KEY SHARE', [$workId->value->value]) === null) {
            throw new \LogicException('Cannot attach missing work to a run.');
        }
        $run = LedgerRow::one('SELECT cancel_requested_at IS NOT NULL AS cancelled FROM prc_process_runs WHERE id = ? FOR UPDATE', [$runId->value]);
        if ($run === null || ($role === AccountingRole::Required && $run->bool('cancelled'))) {
            throw new \LogicException('Cannot attach required work to a cancelled or missing run.');
        }
        DB::insert(
            'INSERT INTO prc_process_run_work (run_id, work_id, relation, accounting_role) VALUES (?, ?, ?, ?)',
            [$runId->value, $workId->value->value, $relation->value, $role->value],
        );
    }

    /** @param array<string, ProcessUuid> $ids */
    public function grantLocked(AuthoritySnapshot $snapshot, LockedPrefix $prefix, array $ids): ExecutionLease
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

    /** @param array<string, ProcessUuid> $ids */
    public function waitForCapacityLocked(AuthoritySnapshot $snapshot, LockedPrefix $prefix, array $ids): void
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

    public function persistCheckpointCas(
        AuthoritySnapshot $snapshot,
        ExecutionLease $lease,
        LedgerRow $work,
        StageCheckpoint $progress,
        int $expectedSeq,
    ): CheckpointResult {
        $merged = self::mergeProgress($this->lockedCheckpoint($work), $progress);
        $payload = $this->registry->payloadCodec()->encodeCheckpoint($merged);
        $row = LedgerRow::one(
            "UPDATE prc_process_work
             SET checkpoint = ?::jsonb, checkpoint_schema_version = ?, checkpoint_seq = checkpoint_seq + 1,
                 lease_until = clock_timestamp() + make_interval(secs => ?), heartbeat_at = clock_timestamp(), updated_at = clock_timestamp()
             WHERE id = ? AND generation = ? AND owner_token = ? AND state = 'running'
               AND lease_until > clock_timestamp() AND checkpoint_seq = ?
             RETURNING checkpoint_seq, lease_until::text AS lease_until",
            [$payload->json, $payload->schemaVersion, $this->policy->leaseSeconds($lease->stage), $lease->workId->value->value, $lease->generation, $lease->token->value, $expectedSeq],
        );
        if ($row === null) {
            return CheckpointResult::refused(CheckpointStatus::LostOwnership);
        }
        $this->registry->resultRepository($lease->stage)->storeProgress($snapshot->input, $progress);
        DB::update('UPDATE prc_process_attempts SET last_checkpoint_at = clock_timestamp() WHERE id = ? AND finished_at IS NULL', [$lease->attemptId->value]);

        return CheckpointResult::saved($row->int('checkpoint_seq'), new DbInstant(new \DateTimeImmutable($row->string('lease_until'))), $merged);
    }

    public function lockedCheckpoint(LedgerRow $work): ?WorkCheckpointEnvelope
    {
        $checkpoint = $work->nullableString('checkpoint');

        return $this->registry->payloadCodec()->decodeCheckpoint(
            new VersionedPayload($checkpoint, $checkpoint === null ? null : $work->int('checkpoint_schema_version')),
        );
    }

    public static function mergeProgress(?WorkCheckpointEnvelope $current, StageCheckpoint $progress): WorkCheckpointEnvelope
    {
        $merged = $current === null ? new WorkCheckpointEnvelope($progress, new NoProviderRecovery) : $current->withProgress($progress);
        if ($progress instanceof ConsumesProviderOperation
            && ($physical = $progress->consumedPhysicalCallId()) !== null) {
            $plan = $merged->providerRecoveryPlan;
            if ($plan instanceof ProviderRecoveryPlan && $plan->physicalCallId != $physical) {
                throw new \LogicException('Progress cannot consume a different pinned provider operation.');
            }
            $merged = $merged->withoutProviderRecovery();
        }

        return $merged;
    }

    private static function derivedId(ProcessUuid $seed, string $purpose): ProcessUuid
    {
        $h = hash('sha256', 'process-derived:v1:'.$purpose.':'.$seed->value);

        return new ProcessUuid(substr($h, 0, 8).'-'.substr($h, 8, 4).'-5'.substr($h, 13, 3).'-a'.substr($h, 17, 3).'-'.substr($h, 20, 12));
    }
}
