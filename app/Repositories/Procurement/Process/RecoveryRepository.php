<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Dto\Procurement\Process\AuthoritySnapshot;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\DeliveryGeneration;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\ProviderRecoveryPlan;
use App\Dto\Procurement\Process\ScopeDefinition;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\Process\WorkCheckpointEnvelope;
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
 * Candidate selectors и locked recovery transitions RecoveryReconciler. Selection всегда unlocked и bounded; каждая locked операция — отдельная ProcessTransaction
 * одного work под полным prefix control SHARE → handler SHARE → capacity UPDATE → subjects UPDATE →
 * work UPDATE SKIP LOCKED, после чего все predicates (state, deadline, input/head, membership,
 * controls) повторяются: изменившийся lock set — Reload, а не поздний lock. Recovery только
 * закрывает attempt/освобождает group/меняет state по доказанным фактам: provider outcome
 * выбирает ProviderRecoveryResolver, storage/provider reserves здесь не освобождаются.
 */
final readonly class RecoveryRepository
{
    /** blocked(provider_result_unknown): следующая reconciliation через 30 s по DB clock, due_at остаётся NULL. */
    private const int PROVIDER_RECOVERY_SECONDS = 30;

    /** Handler hold: три distinct input_hash одной signature той же версии за окно от finished_at кандидата. */
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
    ) {}

    /**
     * Expired running: lease истёк минимум 2 s назад; locked решение — lease_until <= now.
     *
     * @return list<WorkId>
     */
    public function expiredRunning(int $limit): array
    {
        return self::ids(LedgerRow::all(
            "SELECT id FROM prc_process_work WHERE state = 'running' AND lease_until < clock_timestamp() - interval '2 seconds'
             ORDER BY lease_until, id LIMIT ?",
            [$limit],
        ));
    }

    /**
     * Admitted с истёкшей delivery либо под committed pause/held executing handler.
     *
     * @return list<WorkId>
     */
    public function admittedCandidates(int $limit): array
    {
        [$control, $bindings] = $this->controlPredicate(WorkState::Admitted);

        return self::ids(LedgerRow::all(
            "SELECT id FROM prc_process_work
             WHERE state = 'admitted' AND (dispatch_claim_until <= clock_timestamp() OR {$control})
             ORDER BY dispatch_claim_until, id LIMIT ?",
            [...$bindings, $limit],
        ));
    }

    /**
     * Blocked(paused|handler_hold), чьи scopes сейчас не paused и executing handler не held.
     * Pause/held-пары исключены в SQL, поэтому постоянно paused work не занимает batch.
     *
     * @return list<WorkId>
     */
    public function releasableBlocked(int $limit): array
    {
        [$control, $bindings] = $this->controlPredicate(WorkState::Blocked);

        return self::ids(LedgerRow::all(
            "SELECT id FROM prc_process_work
             WHERE state = 'blocked' AND reason_code IN ('paused', 'handler_hold') AND NOT ({$control})
             ORDER BY updated_at, id LIMIT ?",
            [...$bindings, $limit],
        ));
    }

    /**
     * Cancelled work с pinned provider plan. Due у cancelled запрещён схемой, поэтому очередь — по
     * updated_at: reschedule переставляет в конец.
     *
     * @return list<WorkId>
     */
    public function cancelledProvider(int $limit): array
    {
        return self::ids(LedgerRow::all(
            "SELECT id FROM prc_process_work
             WHERE state = 'cancelled' AND checkpoint->'provider_recovery_plan'->>'variant' = 'provider'
             ORDER BY updated_at, id LIMIT ?",
            [$limit],
        ));
    }

    /**
     * selector: provider_recovery_due_at, а не lease_until/due_at; earliest-due fairness.
     *
     * @return list<WorkId>
     */
    public function blockedProvider(int $limit): array
    {
        return self::ids(LedgerRow::all(
            "SELECT id FROM prc_process_work
             WHERE state = 'blocked' AND reason_code = 'provider_result_unknown' AND provider_recovery_due_at <= clock_timestamp()
             ORDER BY provider_recovery_due_at, id LIMIT ?",
            [$limit],
        ));
    }

    /**
     * Durable unchecked fault evidence: окно отсчитывается от finished_at кандидата, а не от now.
     *
     * @return list<array{attempt_id: string, handler_version: string, signature: string, finished_at: string, stage: StageCode}>
     */
    public function uncheckedFaults(int $limit): array
    {
        $candidates = [];
        foreach (LedgerRow::all(
            "SELECT a.id AS attempt_id, a.handler_version, encode(a.fault_signature, 'hex') AS signature, a.finished_at::text AS finished_at, w.stage
             FROM prc_process_attempts a JOIN prc_process_work w ON w.id = a.work_id
             WHERE a.fault_signature IS NOT NULL AND a.finished_at IS NOT NULL AND a.fault_policy_checked_at IS NULL
             ORDER BY a.finished_at, a.id LIMIT ?",
            [$limit],
        ) as $row) {
            $candidates[] = [
                'attempt_id' => $row->string('attempt_id'),
                'handler_version' => $row->string('handler_version'),
                'signature' => $row->string('signature'),
                'finished_at' => $row->string('finished_at'),
                'stage' => StageCode::from($row->string('stage')),
            ];
        }

        return $candidates;
    }

    /**
     * Pinned provider plan и checkpoint_seq до locks: основа pre-read resolver. null — нет checkpoint.
     *
     * @return array{plan: ProviderRecoveryPlan|null, seq: int}|null
     */
    public function pinnedPlan(WorkId $workId): ?array
    {
        $row = LedgerRow::one(
            'SELECT checkpoint::text AS checkpoint, checkpoint_schema_version, checkpoint_seq FROM prc_process_work WHERE id = ?',
            [$workId->value->value],
        );
        if ($row === null) {
            return null;
        }
        $checkpoint = $row->nullableString('checkpoint');
        $seq = $row->int('checkpoint_seq');
        // Stage codec нужен только когда в envelope реально есть provider plan: reconcile process без
        // привязанных stage codecs не должен падать на обычном stage progress без provider recovery.
        $decoded = $checkpoint === null ? null : json_decode($checkpoint, true);
        $plan = is_array($decoded) && is_array($decoded['provider_recovery_plan'] ?? null) && ($decoded['provider_recovery_plan']['variant'] ?? null) === 'provider'
            ? $this->registry->payloadCodec()->decodeCheckpoint(new VersionedPayload($checkpoint, $row->int('checkpoint_schema_version')))?->providerRecoveryPlan
            : null;

        return ['plan' => $plan instanceof ProviderRecoveryPlan ? $plan : null, 'seq' => $seq];
    }

    /**
     * Expired running → ready (durable checkpoint сохранён), blocked(paused|handler_hold|
     * provider_result_unknown) либо superseded. Старый attempt interrupted, generation+1 отзывает
     * владельца, вся group освобождается; provider-linked work идёт по $finding полного resolver.
     *
     * @param  array<string, ProcessUuid>  $ids
     */
    public function recoverRunning(AuthoritySnapshot $snapshot, ?ProviderRecoveryPlan $finding, int $pinnedSeq, ProviderRecoveryResolver $resolver, array $ids): RecoveryOutcome
    {
        CallerTransaction::require('Expired running recovery');
        $prefix = $this->lock($snapshot);
        if ($prefix instanceof RecoveryOutcome) {
            return $prefix;
        }
        $work = $prefix->work;
        // Renewed heartbeat либо уже завершивший valid commit владелец выигрывают: recovery ничего не пишет.
        if ($work->string('state') !== WorkState::Running->value || ! $work->bool('lease_expired')) {
            return RecoveryOutcome::Skipped;
        }
        if ($work->int('checkpoint_seq') !== $pinnedSeq) {
            return RecoveryOutcome::Skipped;
        }
        $generation = $work->int('generation');
        $attempt = LedgerRow::one(
            'SELECT id, handler_version FROM prc_process_attempts WHERE work_id = ? AND generation = ? AND finished_at IS NULL',
            [$snapshot->workId->value->value, $generation],
        );
        $group = $prefix->group;
        if ($group === null || $attempt === null || ! $this->authority->exactMembership($snapshot, $prefix, 'running')
            || $group->nullableString('attempt_id') !== $attempt->string('id')) {
            return RecoveryOutcome::Corrupt;
        }
        if ($attempt->string('handler_version') !== $snapshot->handler->handlerVersion) {
            return RecoveryOutcome::Reload;
        }
        // A progress-only checkpoint can be preserved byte-for-byte without a stage codec binding.
        $hasProviderPlan = ($this->pinnedPlan($snapshot->workId)['plan'] ?? null) instanceof ProviderRecoveryPlan;
        $current = $finding !== null || $hasProviderPlan ? $this->works->lockedCheckpoint($work) : null;
        $pinned = $current?->providerRecoveryPlan;
        if ($pinned instanceof ProviderRecoveryPlan && $finding !== null && ! self::sameOperation($pinned, $finding)) {
            throw new \LogicException('Provider recovery finding does not match the pinned operation.');
        }
        // A missing resolver/outcome cannot turn a durable provider directive into a new send grant.
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
        $hold = $terminal ?? $this->holdReason($prefix);
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
        $payload = $plan === null ? null : $this->envelope($snapshot, $current, $plan);
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
        $closed = DB::update(
            "UPDATE prc_process_attempts SET finished_at = clock_timestamp(), terminal_kind = 'interrupted' WHERE id = ? AND finished_at IS NULL",
            [$attempt->string('id')],
        );
        if ($closed !== 1) {
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

    /**
     * Admitted: committed pause/held handler освобождает всю group в blocked; истёкшая delivery
     * получает новый dispatch generation с той же group и обычным wake; stale input — superseded.
     * Ни новая group, ни execution attempt не создаются.
     *
     * @param  array<string, ProcessUuid>  $ids
     */
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
        $hold = $this->holdReason($prefix);
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
        $updated = DB::update(
            "UPDATE prc_admission_groups SET dispatch_generation = ?, version = version + 1
             WHERE id = ? AND state = 'admitted' AND dispatch_generation = ? AND version = ?",
            [$row->int('dispatch_generation'), $group->string('id'), $dispatch, $group->int('version')],
        );
        if ($updated !== 1) {
            throw new \LogicException('Redelivery must move the whole admission group to the new delivery generation.');
        }
        $this->transitions->recordWork(
            $ids['event'], $ids['projection'], $snapshot->workId->value, $row->int('transition_seq'),
            WorkState::Admitted->value, WorkState::Admitted->value, 'claim_redelivery', ActorKind::Reconciler, $work->int('generation'),
        );
        $this->outbox->enqueueWorkWake($ids['wake'], $snapshot->workId, new DeliveryGeneration($row->int('dispatch_generation')), $stage);

        return RecoveryOutcome::Recovered;
    }

    /**
     * Blocked(paused|handler_hold) → ready только если входной work актуален, ВСЕ применимые scopes
     * enabled и executing handler healthy. Другие blocked reasons не трогаются (generic resume их
     * не освобождает); pinned provider plan в checkpoint сохраняется.
     *
     * @param  array<string, ProcessUuid>  $ids
     */
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
        if ($this->holdReason($prefix) !== null) {
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

    /**
     * late receipt для уже blocked(provider_result_unknown). Verified outcome → тот же work
     * ready/resume (либо blocked paused/held с сохранённым plan), settlement original reservation,
     * state+checkpoint_seq+transition+projection атомарны. Нет verified outcome → unknown и reserve
     * сохранены, меняется только provider_recovery_due_at. Cancelled/superseded после pre-read:
     * только settlement финансовой истории, без возобновления.
     *
     * @param  array<string, ProcessUuid>  $ids
     */
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
        $due = LedgerRow::one('SELECT provider_recovery_due_at <= clock_timestamp() AS due FROM prc_process_work WHERE id = ?', [$snapshot->workId->value->value]);
        if ($due === null || ! $due->bool('due') || $work->int('checkpoint_seq') !== $pinnedSeq) {
            return RecoveryOutcome::Skipped;
        }
        $current = $this->works->lockedCheckpoint($work);
        $pinned = $current?->providerRecoveryPlan;
        if (! $pinned instanceof ProviderRecoveryPlan || $pinned->mode !== ProviderRecoveryMode::BlockedUnknown) {
            // Linkage обязателен: latest-call heuristic запрещён — incident и следующая попытка по расписанию.
            $this->incidents->open(
                fingerprint: ContentHash::of('provider-unknown-linkage:v1:'.$snapshot->workId->value->value),
                scopeKey: 'work:'.$snapshot->workId->value->value,
                kind: 'provider_unknown_linkage_missing',
                evidence: ['work_id' => $snapshot->workId->value->value],
                incidentId: $ids['incident'],
                eventId: $ids['incident_event'],
                projectionMessageId: $ids['incident_projection'],
            );
            $this->rescheduleProvider($snapshot->workId);

            return RecoveryOutcome::Skipped;
        }
        if ($finding !== null && $finding->receipt !== null && self::sameOperation($pinned, $finding) && $resolver instanceof ProviderRecoveryPolicy) {
            // Factual capture may remain unknown: retain its reservation and reconciliation schedule.
            $resolver->settleLocked($finding);
        }
        if ($finding === null || ($finding->mode === ProviderRecoveryMode::BlockedUnknown && $terminal === null)
            || ! self::sameOperation($pinned, $finding)) {
            $this->rescheduleProvider($snapshot->workId);

            return RecoveryOutcome::Skipped;
        }

        $resolver->settleLocked($finding);
        $stage = $snapshot->handler->stage;
        if ($finding->expectedInputHash->hex !== $snapshot->inputHash->hex || ! $prefix->intentActive
            || $this->resolver->inputInvalidated($snapshot->input, $snapshot->inputRevision, $prefix->subjects)) {
            $this->works->supersedeLocked($snapshot, $prefix, $ids['event'], $ids['projection'], ActorKind::Reconciler);
            $this->outbox->enqueueFeederWake($ids['feed'], $this->policy->parentLane($stage));

            return RecoveryOutcome::Recovered;
        }

        $hold = $terminal ?? $this->holdReason($prefix);
        $next = $hold === null ? WorkState::Ready : WorkState::Blocked;
        $payload = $this->envelope($snapshot, $current, $finding);
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

    /**
     * Cancelled work с pinned provider plan: settlement только по verified finding той же operation и
     * под fence по checkpoint_seq. Resume и supersede здесь не делаются. Unknown или чужая operation
     * переставляет work в конец очереди, чтобы не голодать остальные.
     */
    private function recoverCancelledProvider(AuthoritySnapshot $snapshot, LedgerRow $work, ?ProviderRecoveryPlan $finding, ?BlockedReason $terminal, int $pinnedSeq, ProviderRecoveryResolver $resolver): RecoveryOutcome
    {
        $pinned = $this->works->lockedCheckpoint($work)?->providerRecoveryPlan;
        if (! $pinned instanceof ProviderRecoveryPlan || $work->int('checkpoint_seq') !== $pinnedSeq) {
            return RecoveryOutcome::Skipped;
        }
        if ($finding !== null && $finding->receipt !== null && self::sameOperation($pinned, $finding) && $resolver instanceof ProviderRecoveryPolicy) {
            $resolver->settleLocked($finding);
        }
        if ($finding === null || ($finding->mode === ProviderRecoveryMode::BlockedUnknown && $terminal === null) || ! self::sameOperation($pinned, $finding)) {
            DB::update("UPDATE prc_process_work SET updated_at = clock_timestamp() WHERE id = ? AND state = 'cancelled'", [$snapshot->workId->value->value]);

            return RecoveryOutcome::Skipped;
        }
        $resolver->settleLocked($finding);
        DB::update(
            "UPDATE prc_process_work
             SET checkpoint = NULL, checkpoint_schema_version = NULL, checkpoint_seq = checkpoint_seq + 1, updated_at = clock_timestamp()
             WHERE id = ? AND state = 'cancelled' AND checkpoint_seq = ?",
            [$snapshot->workId->value->value, $pinnedSeq],
        );

        return RecoveryOutcome::Recovered;
    }

    /**
     * Historical handler-fault detector: отдельная tx после finished attempt, не
     * из logger/finalize. Controls FOR SHARE → handler authority FOR UPDATE; кандидат помечается
     * checked в той же tx, что hold/incident, поэтому crash между finish и detector не теряет hold,
     * а повтор идемпотентен. Уже held версия не перезаписывается. true — кандидат обработан этим backend.
     *
     * @param  array{attempt_id: string, handler_version: string, signature: string, finished_at: string, stage: StageCode}  $candidate
     * @param  array<string, ProcessUuid>  $ids
     */
    public function detectFault(array $candidate, array $ids): bool
    {
        CallerTransaction::require('Handler fault detector');
        $stage = $candidate['stage'];
        $version = $candidate['handler_version'];
        $keys = array_map(static fn (ScopeDefinition $scope): string => $scope->key, $this->policy->stageScopes($stage));
        sort($keys, SORT_STRING);
        $controls = LedgerRow::all(
            'SELECT id FROM prc_control_scopes WHERE scope_key IN ('.LedgerRow::placeholders(count($keys)).') ORDER BY scope_key COLLATE "C" FOR SHARE',
            $keys,
        );
        if (count($controls) !== count($keys)) {
            throw new \LogicException('Control scopes of the stage are not materialized: '.$stage->value);
        }
        $hold = LedgerRow::one(
            'SELECT id, state FROM prc_process_handler_holds WHERE stage = ? AND handler_version = ? FOR UPDATE',
            [$stage->value, $version],
        );
        if ($hold === null) {
            throw new \LogicException('Historical handler authority is missing: '.$stage->value.':'.$version);
        }
        $marked = DB::update(
            'UPDATE prc_process_attempts SET fault_policy_checked_at = clock_timestamp() WHERE id = ? AND fault_policy_checked_at IS NULL',
            [$candidate['attempt_id']],
        );
        if ($marked !== 1) {
            return false;
        }
        if ($hold->string('state') === 'held') {
            return true;
        }
        $inputs = (LedgerRow::one(
            "SELECT count(DISTINCT w.input_hash) AS inputs
             FROM prc_process_attempts a JOIN prc_process_work w ON w.id = a.work_id
             WHERE w.stage = ? AND a.handler_version = ? AND a.fault_signature = decode(?, 'hex') AND a.finished_at IS NOT NULL
                AND a.finished_at <= ?::timestamptz AND a.finished_at >= ?::timestamptz - make_interval(mins => ?)",
            [$stage->value, $version, $candidate['signature'], $candidate['finished_at'], $candidate['finished_at'], self::FAULT_WINDOW_MINUTES],
        ) ?? throw new \LogicException('Aggregate query always returns a row.'))->int('inputs');
        if ($inputs < self::FAULT_HOLD_DISTINCT_INPUTS) {
            return true;
        }
        $incident = $this->incidents->open(
            fingerprint: ContentHash::of('handler-hold:v1:'.$stage->value.':'.$version),
            scopeKey: 'handler:'.$stage->value.':'.$version,
            kind: 'handler_hold',
            evidence: ['stage' => $stage->value, 'handler_version' => $version, 'distinct_inputs' => $inputs],
            incidentId: $ids['incident'],
            eventId: $ids['incident_event'],
            projectionMessageId: $ids['incident_projection'],
        );
        DB::update(
            "UPDATE prc_process_handler_holds
             SET state = 'held', version = version + 1, fault_signature = decode(?, 'hex'), incident_id = ?, held_at = clock_timestamp()
             WHERE id = ? AND state = 'healthy'",
            [$candidate['signature'], $incident->value, $hold->string('id')],
        );

        return true;
    }

    /** Scheduling-only: без business transition, attempt и item/semantic budget. */
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

    private function holdReason(LockedPrefix $prefix): ?BlockedReason
    {
        return match (true) {
            ! $prefix->controlsEnabled => BlockedReason::Paused,
            ! $prefix->handlerHealthy => BlockedReason::HandlerHold,
            default => null,
        };
    }

    /** Metadata verified outside locks must still identify the exact pinned operation under locks. */
    private static function sameOperation(ProviderRecoveryPlan $pinned, ProviderRecoveryPlan $finding): bool
    {
        return $pinned->logicalRequestId->value === $finding->logicalRequestId->value
            && $pinned->physicalCallId->value === $finding->physicalCallId->value
            && $pinned->purpose === $finding->purpose
            && $pinned->expectedInputHash->hex === $finding->expectedInputHash->hex
            && $pinned->recipeHash->hex === $finding->recipeHash->hex
            && $pinned->requestEpoch === $finding->requestEpoch
            && $pinned->planVersion === $finding->planVersion;
    }

    /** BlockedUnknown сохраняет reserve: settlement только для доказанных исходов. */
    private function settle(ProviderRecoveryResolver $resolver, ?ProviderRecoveryPlan $finding): void
    {
        if ($finding !== null && ($finding->mode !== ProviderRecoveryMode::BlockedUnknown
            || ($finding->receipt !== null && $resolver instanceof ProviderRecoveryPolicy))) {
            $resolver->settleLocked($finding);
        }
    }

    /** Envelope сохраняет stage progress; работе без checkpoint даётся initial progress её codec. */
    private function envelope(AuthoritySnapshot $snapshot, ?WorkCheckpointEnvelope $current, ProviderRecoveryPlan $plan): VersionedPayload
    {
        $progress = $current->stageProgress ?? $this->registry->initialProgress($snapshot->handler->stage);

        return $this->registry->payloadCodec()->encodeCheckpoint(new WorkCheckpointEnvelope($progress, $plan));
    }

    /**
     * SQL-предикат work, чья control closure содержит paused scope либо чья executing handler
     * version held; 'FALSE' — если таких нет. Выводится из policy scope keys, а не из N+1 запросов.
     *
     * @return array{string, list<string>}
     */
    private function controlPredicate(WorkState $state): array
    {
        $paused = array_map(
            static fn (LedgerRow $row): string => $row->string('scope_key'),
            LedgerRow::all("SELECT scope_key FROM prc_control_scopes WHERE desired_mode = 'paused'"),
        );
        $pairs = [];
        if ($paused !== []) {
            foreach (LedgerRow::all('SELECT DISTINCT stage, cohort_key FROM prc_process_work WHERE state = ?', [$state->value]) as $row) {
                $code = StageCode::tryFrom($row->string('stage'));
                if ($code === null) {
                    continue;
                }
                $keys = array_map(static fn (ScopeDefinition $scope): string => $scope->key, $this->policy->workScopes($code, $row->string('cohort_key')));
                if (array_intersect($keys, $paused) !== []) {
                    $pairs[] = [$code->value, $row->string('cohort_key')];
                }
            }
        }
        $held = [];
        foreach (LedgerRow::all("SELECT stage, handler_version FROM prc_process_handler_holds WHERE state = 'held'") as $row) {
            $code = StageCode::tryFrom($row->string('stage'));
            if ($code !== null && ($this->registry->handlerVersion($code) ?? $row->string('handler_version')) === $row->string('handler_version')) {
                $held[$code->value] = true;
            }
        }
        $conditions = [];
        $bindings = [];
        if ($held !== []) {
            $conditions[] = 'stage IN ('.LedgerRow::placeholders(count($held)).')';
            $bindings = [...$bindings, ...array_keys($held)];
        }
        if ($pairs !== []) {
            $conditions[] = '(stage, cohort_key) IN ('.implode(', ', array_fill(0, count($pairs), '(?, ?)')).')';
            foreach ($pairs as [$stage, $cohort]) {
                $bindings[] = $stage;
                $bindings[] = $cohort;
            }
        }

        return [$conditions === [] ? 'FALSE' : '('.implode(' OR ', $conditions).')', $bindings];
    }

    /**
     * @param  list<LedgerRow>  $rows
     * @return list<WorkId>
     */
    private static function ids(array $rows): array
    {
        return array_map(static fn (LedgerRow $row): WorkId => new WorkId(new ProcessUuid($row->string('id'))), $rows);
    }
}
