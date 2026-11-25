<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Dto\Procurement\Process\AuthoritySnapshot;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\DeliveryGeneration;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\ProviderRecoveryPlan;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\ActorKind;
use App\Enums\Procurement\Process\BlockedReason;
use App\Enums\Procurement\Process\PrefixFailure;
use App\Enums\Procurement\Process\ProviderRecoveryMode;
use App\Enums\Procurement\Process\RecoveryOutcome;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\WorkState;
use App\Services\Procurement\Process\Contracts\ProviderRecoveryPolicy;
use App\Services\Procurement\Process\Contracts\ProviderRecoveryResolver;
use App\Services\Procurement\Process\ProcessPolicy;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Process\SubjectLockResolver;
use Illuminate\Support\Facades\DB;

/**
 * Candidate selectors и locked recovery transitions RecoveryReconciler.
 */
final readonly class RecoveryRepository
{
    private const int PROVIDER_RECOVERY_SECONDS = 30;

    private const int FAULT_WINDOW_MINUTES = 5;

    private const int FAULT_HOLD_DISTINCT_INPUTS = 3;

    public function __construct(
        private AuthorityRepository $authority,
        private WorkStoreRepository $works,
        private TransitionRepository $transitions,
        private OutboxRepository $outbox,
        private IncidentRepository $incidents,
        private SubjectLockResolver $resolver,
        private StageRegistry $registry,
        private ProcessPolicy $policy,
        private ?RecoveryQueryRepository $queries = null,
    ) {}

    /**
     * @param  array{sort: string, id: string}|null  $cursor
     * @return list<WorkId>
     */
    public function expiredRunning(int $limit, ?array &$cursor = null): array
    {
        return $this->queries()->expiredRunning($limit, $cursor);
    }

    /**
     * @param  array{sort: string, id: string}|null  $cursor
     * @return list<WorkId>
     */
    public function admittedCandidates(int $limit, ?array &$cursor = null): array
    {
        return $this->queries()->admittedCandidates($limit, $cursor);
    }

    /**
     * @param  array{sort: string, id: string}|null  $cursor
     * @return list<WorkId>
     */
    public function releasableBlocked(int $limit, ?array &$cursor = null): array
    {
        return $this->queries()->releasableBlocked($limit, $cursor);
    }

    /**
     * @param  array{sort: string, id: string}|null  $cursor
     * @return list<WorkId>
     */
    public function cancelledProvider(int $limit, ?array &$cursor = null): array
    {
        return $this->queries()->cancelledProvider($limit, $cursor);
    }

    /**
     * @param  array{sort: string, id: string}|null  $cursor
     * @return list<WorkId>
     */
    public function blockedProvider(int $limit, ?array &$cursor = null): array
    {
        return $this->queries()->blockedProvider($limit, $cursor);
    }

    /**
     * @param  array{sort: string, id: string}|null  $cursor
     * @return list<array{attempt_id: string, work_id: string, handler_version: string, signature: string, finished_at: string, stage: StageCode}>
     */
    public function uncheckedFaults(int $limit, ?array &$cursor = null): array
    {
        return $this->queries()->uncheckedFaults($limit, $cursor);
    }

    /** @return array{plan: ProviderRecoveryPlan|null, seq: int}|null */
    public function pinnedPlan(WorkId|LedgerRow $work): ?array
    {
        return $this->queries()->pinnedPlan($work);
    }

    /** @param array<string, ProcessUuid> $ids */
    public function recoverRunning(AuthoritySnapshot $snapshot, ?ProviderRecoveryPlan $finding, int $pinnedSeq, ProviderRecoveryResolver $resolver, array $ids): RecoveryOutcome
    {
        CallerTransaction::require('Expired running recovery');
        $prefix = $this->lock($snapshot);
        if ($prefix instanceof RecoveryOutcome) {
            return $prefix;
        }
        $work = $prefix->work;
        if ($work->string('state') !== WorkState::Running->value || ! $work->bool('lease_expired') || $work->int('checkpoint_seq') !== $pinnedSeq) {
            return RecoveryOutcome::Skipped;
        }
        $generation = $work->int('generation');
        $attempt = $this->queries()->runningAttempt($snapshot->workId, $generation);
        $group = $prefix->group;
        if ($group === null || $attempt === null || ! $this->authority->exactMembership($snapshot, $prefix, 'running')
            || $group->nullableString('attempt_id') !== $attempt->string('id')) {
            return RecoveryOutcome::Corrupt;
        }
        if ($attempt->string('handler_version') !== $snapshot->handler->handlerVersion) {
            return RecoveryOutcome::Reload;
        }
        $hasProviderPlan = ($this->pinnedPlan($work)['plan'] ?? null) instanceof ProviderRecoveryPlan;
        $current = $finding !== null || $hasProviderPlan ? $this->works->lockedCheckpoint($work) : null;
        $pinned = $current?->providerRecoveryPlan;
        if ($pinned instanceof ProviderRecoveryPlan && $finding !== null && ! RecoveryQueryRepository::sameOperation($pinned, $finding)) {
            throw new \LogicException('Provider recovery finding does not match the pinned operation.');
        }
        $missingResolution = $pinned instanceof ProviderRecoveryPlan && $finding === null;
        $plan = $finding ?? ($pinned instanceof ProviderRecoveryPlan ? $pinned : null);
        $resolver->revokeUnclaimedLocked($snapshot->workId);
        $stage = $snapshot->handler->stage;
        if (($plan !== null && $plan->expectedInputHash->hex !== $snapshot->inputHash->hex)
            || ! $prefix->intentActive || $this->resolver->inputInvalidated($snapshot->input, $snapshot->inputRevision, $prefix->subjects)) {
            $this->settle($resolver, $finding);
            $this->works->supersedeLocked($snapshot, $prefix, $ids['event'], $ids['projection'], ActorKind::Reconciler);
            $this->outbox->enqueueFeederWake($ids['feed'], $this->policy->parentLane($stage));

            return RecoveryOutcome::Recovered;
        }

        $terminal = $plan !== null && $resolver instanceof ProviderRecoveryPolicy ? $resolver->terminalReason($plan) : null;
        $unknown = $plan?->mode === ProviderRecoveryMode::BlockedUnknown && $terminal === null;
        $hold = $terminal ?? $this->queries()->holdReason($prefix);
        if ($unknown) {
            [$state, $reason] = [WorkState::Blocked, BlockedReason::ProviderResultUnknown->value];
        } elseif ($missingResolution) {
            [$state, $reason] = [WorkState::Blocked, BlockedReason::PolicyReview->value];
        } elseif ($hold !== null) {
            [$state, $reason] = [WorkState::Blocked, $hold->value];
        } else {
            [$state, $reason] = [WorkState::Ready, null];
        }
        $this->settle($resolver, $finding);
        $payload = $plan === null ? null : $this->queries()->envelope($snapshot, $current, $plan);
        $row = LedgerRow::one(
            "UPDATE prc_process_work
             SET state = ?, reason_code = ?, generation = generation + 1, owner_token = NULL, lease_until = NULL,
                 dispatch_claim_until = NULL, due_at = NULL,
                 provider_recovery_due_at = CASE WHEN ?::int = 1 THEN clock_timestamp() + make_interval(secs => ?) ELSE NULL END,
                 checkpoint = CASE WHEN ?::int = 1 THEN ?::jsonb ELSE checkpoint END,
                 checkpoint_schema_version = CASE WHEN ?::int = 1 THEN ?::smallint ELSE checkpoint_schema_version END,
                 checkpoint_seq = checkpoint_seq + ?, infrastructure_interruptions = infrastructure_interruptions + 1,
                 transition_seq = transition_seq + 1, updated_at = clock_timestamp()
             WHERE id = ? AND generation = ? AND owner_token = ? AND state = 'running' AND lease_until <= clock_timestamp()
             RETURNING generation, transition_seq",
            [
                $state->value, $reason, (int) $unknown, self::PROVIDER_RECOVERY_SECONDS,
                (int) ($payload !== null), $payload?->json, (int) ($payload !== null), $payload?->schemaVersion, (int) ($payload !== null),
                $snapshot->workId->value->value, $generation, $work->nullableString('owner_token'),
            ],
        ) ?? throw new \LogicException('Recovery fence did not match the locked expired work.');
        if (DB::update("UPDATE prc_process_attempts SET finished_at = clock_timestamp(), terminal_kind = 'interrupted' WHERE id = ? AND finished_at IS NULL", [$attempt->string('id')]) !== 1) {
            throw new \LogicException('Recovery must close exactly the expired attempt.');
        }
        $this->works->releaseGroupLocked($group, count($prefix->members), 'lease_recovery');
        $this->transitions->recordWork(
            $ids['event'], $ids['projection'], $snapshot->workId->value, $row->int('transition_seq'),
            WorkState::Running->value, $state->value, $reason ?? 'lease_recovery', ActorKind::Reconciler, $row->int('generation'),
        );
        if ($state === WorkState::Ready) {
            $this->outbox->enqueueFeederWake($ids['feed'], $this->policy->parentLane($stage));
        }

        return RecoveryOutcome::Recovered;
    }

    /** @param array<string, ProcessUuid> $ids */
    public function recoverAdmitted(AuthoritySnapshot $snapshot, array $ids): RecoveryOutcome
    {
        CallerTransaction::require('Admitted recovery');
        $prefix = $this->lock($snapshot);
        if ($prefix instanceof RecoveryOutcome) {
            return $prefix;
        }
        $work = $prefix->work;
        if ($work->string('state') !== WorkState::Admitted->value) {
            return RecoveryOutcome::Skipped;
        }
        $group = $prefix->group;
        if ($group === null || ! $this->authority->exactMembership($snapshot, $prefix, 'admitted')) {
            return RecoveryOutcome::Corrupt;
        }
        $stage = $snapshot->handler->stage;
        if (! $prefix->intentActive || $this->resolver->inputInvalidated($snapshot->input, $snapshot->inputRevision, $prefix->subjects)) {
            $this->works->supersedeLocked($snapshot, $prefix, $ids['event'], $ids['projection'], ActorKind::Reconciler);
            $this->outbox->enqueueFeederWake($ids['feed'], $this->policy->parentLane($stage));

            return RecoveryOutcome::Recovered;
        }

        $dispatch = $work->int('dispatch_generation');
        $hold = $this->queries()->holdReason($prefix);
        if ($hold !== null) {
            $row = LedgerRow::one(
                "UPDATE prc_process_work SET state = 'blocked', reason_code = ?, dispatch_claim_until = NULL,
                     transition_seq = transition_seq + 1, updated_at = clock_timestamp()
                 WHERE id = ? AND state = 'admitted' AND dispatch_generation = ?
                 RETURNING transition_seq",
                [$hold->value, $snapshot->workId->value->value, $dispatch],
            ) ?? throw new \LogicException('Admitted release fence did not match the locked work.');
            $this->works->releaseGroupLocked($group, count($prefix->members), $hold->value);
            $this->transitions->recordWork(
                $ids['event'], $ids['projection'], $snapshot->workId->value, $row->int('transition_seq'),
                WorkState::Admitted->value, WorkState::Blocked->value, $hold->value, ActorKind::Reconciler, $work->int('generation'),
            );

            return RecoveryOutcome::Recovered;
        }
        if (! $work->bool('claim_expired')) {
            return RecoveryOutcome::Skipped;
        }

        $row = LedgerRow::one(
            "UPDATE prc_process_work
             SET dispatch_generation = dispatch_generation + 1, dispatch_claim_until = clock_timestamp() + make_interval(secs => ?),
                 transition_seq = transition_seq + 1, updated_at = clock_timestamp()
             WHERE id = ? AND state = 'admitted' AND dispatch_generation = ? AND dispatch_claim_until <= clock_timestamp()
             RETURNING dispatch_generation, transition_seq",
            [$this->policy->claimVisibilitySeconds($stage), $snapshot->workId->value->value, $dispatch],
        ) ?? throw new \LogicException('Redelivery fence did not match the locked admitted work.');
        if (DB::update(
            "UPDATE prc_admission_groups SET dispatch_generation = ?, version = version + 1
             WHERE id = ? AND state = 'admitted' AND dispatch_generation = ? AND version = ?",
            [$row->int('dispatch_generation'), $group->string('id'), $dispatch, $group->int('version')],
        ) !== 1) {
            throw new \LogicException('Redelivery must move the whole admission group to the new delivery generation.');
        }
        $this->transitions->recordWork(
            $ids['event'], $ids['projection'], $snapshot->workId->value, $row->int('transition_seq'),
            WorkState::Admitted->value, WorkState::Admitted->value, 'claim_redelivery', ActorKind::Reconciler, $work->int('generation'),
        );
        $this->outbox->enqueueWorkWake($ids['wake'], $snapshot->workId, new DeliveryGeneration($row->int('dispatch_generation')), $stage);

        return RecoveryOutcome::Recovered;
    }

    /** @param array<string, ProcessUuid> $ids */
    public function releaseBlocked(AuthoritySnapshot $snapshot, array $ids): RecoveryOutcome
    {
        CallerTransaction::require('Blocked release');
        $prefix = $this->lock($snapshot);
        if ($prefix instanceof RecoveryOutcome) {
            return $prefix;
        }
        $work = $prefix->work;
        $reason = $work->nullableString('reason_code');
        if ($work->string('state') !== WorkState::Blocked->value
            || ! in_array($reason, [BlockedReason::Paused->value, BlockedReason::HandlerHold->value], true)) {
            return RecoveryOutcome::Skipped;
        }
        if ($prefix->group !== null || $prefix->members !== []) {
            return RecoveryOutcome::Corrupt;
        }
        $stage = $snapshot->handler->stage;
        if (! $prefix->intentActive || $this->resolver->inputInvalidated($snapshot->input, $snapshot->inputRevision, $prefix->subjects)) {
            $this->works->supersedeLocked($snapshot, $prefix, $ids['event'], $ids['projection'], ActorKind::Reconciler);
            $this->outbox->enqueueFeederWake($ids['feed'], $this->policy->parentLane($stage));

            return RecoveryOutcome::Recovered;
        }
        if ($this->queries()->holdReason($prefix) !== null) {
            return RecoveryOutcome::Skipped;
        }
        $row = LedgerRow::one(
            "UPDATE prc_process_work SET state = 'ready', reason_code = NULL, transition_seq = transition_seq + 1, updated_at = clock_timestamp()
             WHERE id = ? AND state = 'blocked' AND reason_code = ?
             RETURNING transition_seq",
            [$snapshot->workId->value->value, $reason],
        ) ?? throw new \LogicException('Blocked release fence did not match the locked work.');
        $this->transitions->recordWork(
            $ids['event'], $ids['projection'], $snapshot->workId->value, $row->int('transition_seq'),
            WorkState::Blocked->value, WorkState::Ready->value, 'control_released', ActorKind::Reconciler, $work->int('generation'),
        );
        $this->outbox->enqueueFeederWake($ids['feed'], $this->policy->parentLane($stage));

        return RecoveryOutcome::Recovered;
    }

    /** @param array<string, ProcessUuid> $ids */
    public function recoverProvider(AuthoritySnapshot $snapshot, ?ProviderRecoveryPlan $finding, int $pinnedSeq, ProviderRecoveryResolver $resolver, array $ids): RecoveryOutcome
    {
        CallerTransaction::require('Blocked provider recovery');
        $prefix = $this->lock($snapshot);
        if ($prefix instanceof RecoveryOutcome) {
            return $prefix;
        }
        $work = $prefix->work;
        $state = $work->string('state');
        $unknown = $state === WorkState::Blocked->value && $work->nullableString('reason_code') === BlockedReason::ProviderResultUnknown->value;
        $terminal = $finding !== null && $resolver instanceof ProviderRecoveryPolicy ? $resolver->terminalReason($finding) : null;
        if (! $unknown) {
            if ($state === WorkState::Cancelled->value) {
                return $this->recoverCancelledProvider($snapshot, $prefix->work, $finding, $terminal, $pinnedSeq, $resolver);
            }
            if ($finding !== null && ($finding->mode !== ProviderRecoveryMode::BlockedUnknown || $terminal !== null) && $state === WorkState::Superseded->value) {
                $resolver->settleLocked($finding);

                return RecoveryOutcome::Recovered;
            }

            return RecoveryOutcome::Skipped;
        }
        if ($prefix->group !== null || $prefix->members !== []) {
            return RecoveryOutcome::Corrupt;
        }
        if (! $this->queries()->providerRecoveryDue($snapshot->workId) || $work->int('checkpoint_seq') !== $pinnedSeq) {
            return RecoveryOutcome::Skipped;
        }
        $current = $this->works->lockedCheckpoint($work);
        $pinned = $current?->providerRecoveryPlan;
        if (! $pinned instanceof ProviderRecoveryPlan || $pinned->mode !== ProviderRecoveryMode::BlockedUnknown) {
            $this->incidents->open(
                fingerprint: ContentHash::of('provider-unknown-linkage:v1:'.$snapshot->workId->value->value),
                scopeKey: 'work:'.$snapshot->workId->value->value,
                kind: 'provider_unknown_linkage_missing',
                evidence: ['work_id' => $snapshot->workId->value->value],
                incidentId: $ids['incident'], eventId: $ids['incident_event'], projectionMessageId: $ids['incident_projection'],
            );
            $this->rescheduleProvider($snapshot->workId);

            return RecoveryOutcome::Skipped;
        }
        if ($finding === null || ! $this->settleProviderFinding($pinned, $finding, $terminal, $resolver)) {
            $this->rescheduleProvider($snapshot->workId);

            return RecoveryOutcome::Skipped;
        }

        $stage = $snapshot->handler->stage;
        if ($finding->expectedInputHash->hex !== $snapshot->inputHash->hex || ! $prefix->intentActive
            || $this->resolver->inputInvalidated($snapshot->input, $snapshot->inputRevision, $prefix->subjects)) {
            $this->works->supersedeLocked($snapshot, $prefix, $ids['event'], $ids['projection'], ActorKind::Reconciler);
            $this->outbox->enqueueFeederWake($ids['feed'], $this->policy->parentLane($stage));

            return RecoveryOutcome::Recovered;
        }

        $hold = $terminal ?? $this->queries()->holdReason($prefix);
        $next = $hold === null ? WorkState::Ready : WorkState::Blocked;
        $payload = $this->queries()->envelope($snapshot, $current, $finding);
        $row = LedgerRow::one(
            "UPDATE prc_process_work
             SET state = ?, reason_code = ?, provider_recovery_due_at = NULL, checkpoint = ?::jsonb, checkpoint_schema_version = ?::smallint,
                 checkpoint_seq = checkpoint_seq + 1, transition_seq = transition_seq + 1, updated_at = clock_timestamp()
             WHERE id = ? AND state = 'blocked' AND reason_code = 'provider_result_unknown' AND checkpoint_seq = ?
             RETURNING transition_seq",
            [$next->value, $hold?->value, $payload->json, $payload->schemaVersion, $snapshot->workId->value->value, $pinnedSeq],
        ) ?? throw new \LogicException('Provider recovery fence did not match the locked blocked work.');
        $this->transitions->recordWork(
            $ids['event'], $ids['projection'], $snapshot->workId->value, $row->int('transition_seq'),
            WorkState::Blocked->value, $next->value, $hold->value ?? 'provider_outcome_verified', ActorKind::Reconciler, $work->int('generation'),
        );
        if ($next === WorkState::Ready) {
            $this->outbox->enqueueFeederWake($ids['feed'], $this->policy->parentLane($stage));
        }

        return RecoveryOutcome::Recovered;
    }

    private function recoverCancelledProvider(AuthoritySnapshot $snapshot, LedgerRow $work, ?ProviderRecoveryPlan $finding, ?BlockedReason $terminal, int $pinnedSeq, ProviderRecoveryResolver $resolver): RecoveryOutcome
    {
        $pinned = $this->works->lockedCheckpoint($work)?->providerRecoveryPlan;
        if (! $pinned instanceof ProviderRecoveryPlan || $work->int('checkpoint_seq') !== $pinnedSeq) {
            return RecoveryOutcome::Skipped;
        }
        if ($finding === null || ! $this->settleProviderFinding($pinned, $finding, $terminal, $resolver)) {
            DB::update("UPDATE prc_process_work SET updated_at = clock_timestamp() WHERE id = ? AND state = 'cancelled'", [$snapshot->workId->value->value]);

            return RecoveryOutcome::Skipped;
        }
        DB::update(
            "UPDATE prc_process_work
             SET checkpoint = NULL, checkpoint_schema_version = NULL, checkpoint_seq = checkpoint_seq + 1, updated_at = clock_timestamp()
             WHERE id = ? AND state = 'cancelled' AND checkpoint_seq = ?",
            [$snapshot->workId->value->value, $pinnedSeq],
        );

        return RecoveryOutcome::Recovered;
    }

    /** Settles financial evidence even if the verified receipt leaves the work blocked. */
    private function settleProviderFinding(ProviderRecoveryPlan $pinned, ProviderRecoveryPlan $finding, ?BlockedReason $terminal, ProviderRecoveryResolver $resolver): bool
    {
        if (! RecoveryQueryRepository::sameOperation($pinned, $finding)) {
            return false;
        }
        $resolved = $finding->mode !== ProviderRecoveryMode::BlockedUnknown || $terminal !== null;
        if ($resolved || ($finding->receipt !== null && $resolver instanceof ProviderRecoveryPolicy)) {
            $resolver->settleLocked($finding);
        }

        return $resolved;
    }

    /**
     * @param  array{attempt_id: string, handler_version: string, signature: string, finished_at: string, stage: StageCode}  $candidate
     * @param  array<string, ProcessUuid>  $ids
     */
    public function detectFault(array $candidate, array $ids): bool
    {
        CallerTransaction::require('Handler fault detector');
        $stage = $candidate['stage'];
        $version = $candidate['handler_version'];
        $hold = $this->queries()->lockFaultAuthorities($stage, $version);
        if (DB::update('UPDATE prc_process_attempts SET fault_policy_checked_at = clock_timestamp() WHERE id = ? AND fault_policy_checked_at IS NULL', [$candidate['attempt_id']]) !== 1) {
            return false;
        }
        if ($hold->string('state') === 'held') {
            return true;
        }
        $inputs = $this->queries()->distinctFaultInputs($stage, $version, $candidate['signature'], $candidate['finished_at'], self::FAULT_WINDOW_MINUTES);
        if ($inputs < self::FAULT_HOLD_DISTINCT_INPUTS) {
            return true;
        }
        $incident = $this->incidents->open(
            fingerprint: ContentHash::of('handler-hold:v1:'.$stage->value.':'.$version),
            scopeKey: 'handler:'.$stage->value.':'.$version,
            kind: 'handler_hold',
            evidence: ['stage' => $stage->value, 'handler_version' => $version, 'distinct_inputs' => $inputs],
            incidentId: $ids['incident'], eventId: $ids['incident_event'], projectionMessageId: $ids['incident_projection'],
        );
        DB::update(
            "UPDATE prc_process_handler_holds
             SET state = 'held', version = version + 1, fault_signature = decode(?, 'hex'), incident_id = ?, held_at = clock_timestamp()
             WHERE id = ? AND state = 'healthy'",
            [$candidate['signature'], $incident->value, $hold->string('id')],
        );

        return true;
    }

    private function rescheduleProvider(WorkId $workId): void
    {
        CallerTransaction::require('Provider recovery reschedule');
        DB::update(
            "UPDATE prc_process_work SET provider_recovery_due_at = clock_timestamp() + make_interval(secs => ?), updated_at = clock_timestamp()
             WHERE id = ? AND state = 'blocked' AND reason_code = 'provider_result_unknown'",
            [self::PROVIDER_RECOVERY_SECONDS, $workId->value->value],
        );
    }

    private function lock(AuthoritySnapshot $snapshot): LockedPrefix|RecoveryOutcome
    {
        $prefix = $this->authority->lockPrefix($snapshot, skipLockedWork: true);
        if ($prefix === PrefixFailure::SnapshotChanged) {
            return RecoveryOutcome::Reload;
        }

        return $prefix instanceof PrefixFailure ? RecoveryOutcome::Skipped : $prefix;
    }

    private function settle(ProviderRecoveryResolver $resolver, ?ProviderRecoveryPlan $finding): void
    {
        if ($finding !== null && ($finding->mode !== ProviderRecoveryMode::BlockedUnknown
            || ($finding->receipt !== null && $resolver instanceof ProviderRecoveryPolicy))) {
            $resolver->settleLocked($finding);
        }
    }

    private function queries(): RecoveryQueryRepository
    {
        return $this->queries ?? new RecoveryQueryRepository($this->registry, $this->policy);
    }
}
