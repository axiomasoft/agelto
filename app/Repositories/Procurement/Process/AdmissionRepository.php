<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Dto\Procurement\Process\AdmissionResult;
use App\Dto\Procurement\Process\AuthoritySnapshot;
use App\Dto\Procurement\Process\ControlSnapshot;
use App\Dto\Procurement\Process\DeliveryGeneration;
use App\Dto\Procurement\Process\FairnessCursor;
use App\Dto\Procurement\Process\HandlerAuthoritySnapshot;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\TransitionData;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\ActorKind;
use App\Enums\Procurement\Process\AdmissionStatus;
use App\Enums\Procurement\Process\AuthorityGap;
use App\Enums\Procurement\Process\CapacityKind;
use App\Enums\Procurement\Process\HandlerEligibility;
use App\Enums\Procurement\Process\PrefixFailure;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\WorkState;
use App\Services\Procurement\Process\ProcessPayloadCodec;
use App\Services\Procurement\Process\ProcessPolicy;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Process\SubjectLockResolver;
use Illuminate\Support\Facades\DB;

/**
 * Admission ready/due work в admitted. Одна короткая
 * ProcessTransaction на work: полный prefix с SKIP LOCKED только на work, authoritative COUNT
 * active reservation rows после всех capacity locks, cohort cap и fairness cursor parent lane
 * повторно сверяются под теми же locks. Grant атомарно вставляет group + точный набор members,
 * увеличивает dispatch_generation, пишет transition + projection intent, work_wake и cursor.
 *
 * @phpstan-import-type EligibilityPair from AuthorityRepository
 * @phpstan-import-type EligibilityAuthority from AuthorityRepository
 */
final readonly class AdmissionRepository
{
    private const int MAX_RELOADS = 3;

    private const string ELIGIBLE_WORK = "(w.state = 'ready' OR (w.state = 'deferred' AND w.due_at <= clock_timestamp()))
        AND NOT EXISTS (SELECT 1 FROM prc_operation_intents i WHERE i.id = w.intent_id AND i.state = 'pending')";

    public function __construct(
        private AuthorityRepository $authority,
        private WorkStoreRepository $works,
        private TransitionRepository $transitions,
        private OutboxRepository $outbox,
        private IncidentRepository $incidents,
        private ProcessPayloadCodec $codec,
        private ProcessPolicy $policy,
        private ProcessTransaction $transaction,
        private SubjectLockResolver $resolver,
        private StageRegistry $registry,
    ) {}

    /**
     * @param  FairnessCursor  $expectedCursor  cursor parent lane, по которому сделан выбор cohort/stage
     * @param  int  $eligibleCohorts  unlocked selection count; под locks пересчитывается, drift требует нового выбора
     */
    public function admit(WorkId $workId, FairnessCursor $expectedCursor, int $eligibleCohorts): AdmissionResult
    {
        $ids = [
            'group' => ProcessUuid::random(), 'event' => ProcessUuid::random(), 'projection' => ProcessUuid::random(), 'wake' => ProcessUuid::random(),
            'incident' => ProcessUuid::random(), 'incident_event' => ProcessUuid::random(), 'incident_projection' => ProcessUuid::random(),
        ];
        for ($reload = 0; $reload < self::MAX_RELOADS; $reload++) {
            $snapshot = $this->authority->readAuthoritySnapshot($workId);
            if ($snapshot instanceof AuthorityGap) {
                return AdmissionResult::refused(in_array($snapshot, [AuthorityGap::HandlerUnregistered, AuthorityGap::HandlerAuthorityMissing], true)
                    ? AdmissionStatus::HandlerUnavailable
                    : AdmissionStatus::NotEligible);
            }
            $eligibility = $this->authority->readEligibilityAuthority($this->parentEligiblePairs($snapshot));
            $result = $this->transaction->run(fn (): AdmissionResult => $this->admitLocked($snapshot, $expectedCursor, $eligibleCohorts, $ids, $eligibility));
            if ($result->status === AdmissionStatus::InvariantViolation) {
                $this->transaction->run(fn () => $this->incidents->openMembershipIncident(
                    $snapshot->workId, 'lane:'.$this->policy->parentLane($snapshot->handler->stage), 'admit',
                    $ids['incident'], $ids['incident_event'], $ids['incident_projection'],
                ));
            }
            if ($result->status !== AdmissionStatus::SnapshotChanged) {
                return $result;
            }
        }

        return AdmissionResult::refused(AdmissionStatus::SnapshotChanged);
    }

    /**
     * Unlocked выбор: (stage, cohort) с eligible work = ready либо deferred с наступившим due;
     * work pending intent не eligible до активации intent.
     *
     * @param  list<StageCode>  $stages
     * @return list<array{stage: StageCode, cohort: string}>
     */
    public function eligiblePairs(array $stages): array
    {
        $values = array_map(static fn (StageCode $code): string => $code->value, $stages);

        return array_map(static fn (LedgerRow $row): array => ['stage' => StageCode::from($row->string('stage')), 'cohort' => $row->string('cohort_key')], LedgerRow::all(
            'SELECT w.stage, w.cohort_key FROM prc_process_work w
             WHERE w.stage IN ('.LedgerRow::placeholders(count($values)).') AND '.self::ELIGIBLE_WORK.'
             GROUP BY w.stage, w.cohort_key ORDER BY w.cohort_key COLLATE "C", w.stage COLLATE "C"',
            $values,
        ));
    }

    /**
     * Distinct active admission groups каждого cohort через reservation parent lane (не сумма
     * дублирующих child members).
     *
     * @return array<string, int>
     */
    public function activeByCohort(string $parentLaneId): array
    {
        $active = [];
        foreach (LedgerRow::all(
            'SELECT w.cohort_key, count(*) AS active FROM prc_process_reservations r
             JOIN prc_process_work w ON w.id = r.work_id WHERE r.lane_id = ? GROUP BY w.cohort_key',
            [$parentLaneId],
        ) as $row) {
            $active[$row->string('cohort_key')] = $row->int('active');
        }

        return $active;
    }

    /**
     * Bounded candidates внутри уже выбранных cohort и stage: aged (immutable created_at старше
     * age900) первыми по возрасту без priority override; non-aged platform — priority, created_at,
     * id; manual non-aged — FIFO. due_at только predicate, не sort key.
     *
     * @return list<WorkId>
     */
    public function candidates(StageCode $stage, string $cohort): array
    {
        return array_map(static fn (LedgerRow $row): WorkId => new WorkId(new ProcessUuid($row->string('id'))), LedgerRow::all(
            'WITH cutoff AS (SELECT clock_timestamp() - make_interval(secs => ?) AS aged_before)
             SELECT w.id FROM prc_process_work w CROSS JOIN cutoff c
             WHERE w.stage = ? AND w.cohort_key = ? AND '.self::ELIGIBLE_WORK."
             ORDER BY (w.created_at <= c.aged_before) DESC,
                      CASE WHEN w.created_at <= c.aged_before THEN w.created_at END ASC NULLS LAST,
                      CASE WHEN w.created_at > c.aged_before AND w.cohort_key <> 'manual' THEN w.priority ELSE 100 END ASC,
                      w.created_at, w.id
             LIMIT ?",
            [$this->policy->ageSeconds, $stage->value, $cohort, $this->policy->candidateLimit],
        ));
    }

    /**
     * @param  array<string, ProcessUuid>  $ids
     * @param  array<string, EligibilityAuthority|AuthorityGap>  $eligibility
     */
    private function admitLocked(AuthoritySnapshot $snapshot, FairnessCursor $expectedCursor, int $eligibleCohorts, array $ids, array $eligibility): AdmissionResult
    {
        $eligibilitySnapshots = array_values(array_filter(
            $eligibility,
            static fn (array|AuthorityGap $item): bool => is_array($item),
        ));
        $prefix = $this->authority->lockPrefix($snapshot, skipLockedWork: true, eligibilitySnapshots: $eligibilitySnapshots);
        if ($prefix instanceof PrefixFailure) {
            return AdmissionResult::refused(match ($prefix) {
                PrefixFailure::WorkLocked => AdmissionStatus::WorkLocked,
                PrefixFailure::WorkMissing => AdmissionStatus::NotEligible,
                PrefixFailure::SnapshotChanged => AdmissionStatus::SnapshotChanged,
            });
        }
        $work = $prefix->work;
        $state = WorkState::from($work->string('state'));
        if ($state !== WorkState::Ready && ! $work->bool('due')) {
            return AdmissionResult::refused(AdmissionStatus::NotEligible);
        }
        if ($prefix->group !== null) {
            return AdmissionResult::refused(AdmissionStatus::InvariantViolation);
        }
        if ($prefix->intentPending) {
            // Pending intent ждёт завершения active intent того же subject; не supersede.
            return AdmissionResult::refused(AdmissionStatus::NotEligible);
        }
        if (! $prefix->intentActive || $this->resolver->inputInvalidated($snapshot->input, $snapshot->inputRevision, $prefix->subjects)) {
            $this->works->supersedeLocked($snapshot, $prefix, $ids['event'], $ids['projection'], ActorKind::System);

            return AdmissionResult::refused(AdmissionStatus::Superseded);
        }
        if (! $prefix->controlsEnabled) {
            return AdmissionResult::refused(AdmissionStatus::Paused);
        }
        if (! $prefix->handlerHealthy) {
            return AdmissionResult::refused(AdmissionStatus::HandlerUnavailable);
        }
        $code = $snapshot->handler->stage;
        $requiredLanes = $this->policy->requiredLanes($code);
        foreach ($prefix->lanes as $lane) {
            if (in_array($lane->key, $requiredLanes, true) && $lane->outstanding >= $lane->maxOutstanding) {
                return AdmissionResult::refused(AdmissionStatus::CapacityFull);
            }
        }
        $parent = $prefix->lane($this->policy->parentLane($code));
        $cursor = $this->codec->decodeFairnessCursor(new VersionedPayload($parent->fairnessCursor, $parent->fairnessCursorSchemaVersion));
        if ($cursor != $expectedCursor) {
            return AdmissionResult::refused(AdmissionStatus::CursorMoved);
        }
        $currentCohorts = $this->lockedEligibleCohorts($snapshot, $prefix, $eligibility);
        if ($currentCohorts === null) {
            // Новая pair требует более ранних authorities: никакого late lock после work.
            return AdmissionResult::refused(AdmissionStatus::SnapshotChanged);
        }
        if ($currentCohorts !== $eligibleCohorts) {
            return AdmissionResult::refused(AdmissionStatus::CursorMoved);
        }
        $cohort = $work->string('cohort_key');
        if ($currentCohorts >= 2 && ($this->activeByCohort($parent->id)[$cohort] ?? 0) >= (int) ceil($parent->maxOutstanding / 2)) {
            return AdmissionResult::refused(AdmissionStatus::CohortCapped);
        }

        return $this->grantLocked($snapshot, $prefix, $cursor->advance($cohort, $code), $ids);
    }

    /**
     * Fresh ready/due pairs + early-locked controls/handlers/capacity; caller count is never authority.
     *
     * @param  array<string, EligibilityAuthority|AuthorityGap>  $eligibility
     */
    private function lockedEligibleCohorts(AuthoritySnapshot $candidate, LockedPrefix $prefix, array $eligibility): ?int
    {
        $cohorts = [];
        foreach ($this->parentEligiblePairs($candidate) as ['cohort' => $cohort, 'handler' => $handler]) {
            $snapshot = $eligibility[$handler->stage->value.':'.$cohort] ?? null;
            if ($snapshot === null) {
                return null;
            }
            if ($snapshot instanceof AuthorityGap) {
                continue;
            }
            if ($snapshot['handler']->id->value !== $handler->id->value) {
                return null;
            }
            $keys = array_map(static fn (ControlSnapshot $scope): string => $scope->scopeKey, $snapshot['controls']);
            if (array_diff($keys, $prefix->enabledScopeKeys) !== [] || ! in_array($handler->id->value, $prefix->healthyHandlerIds, true)) {
                continue;
            }
            foreach ($this->policy->requiredLanes($handler->stage) as $key) {
                $lane = $prefix->lane($key);
                if ($lane->outstanding >= $lane->maxOutstanding) {
                    continue 2;
                }
            }
            $cohorts[$cohort] = true;
        }

        return count($cohorts);
    }

    /** @return list<EligibilityPair> Same installed-handler boundary as feeder selection. */
    private function parentEligiblePairs(AuthoritySnapshot $candidate): array
    {
        $versions = [];
        $bindings = [];
        foreach ($this->policy->stagesOfParentLane($this->policy->parentLane($candidate->handler->stage)) as $stage) {
            $version = $this->registry->handlerVersion($stage);
            if ($version !== null) {
                $versions[] = '(?::text, ?::text)';
                array_push($bindings, $stage->value, $version);
            }
        }
        if ($versions === []) {
            return [];
        }

        return array_map(static fn (LedgerRow $row): array => [
            'cohort' => $row->string('cohort_key'),
            'handler' => new HandlerAuthoritySnapshot(
                id: new ProcessUuid($row->string('id')),
                stage: StageCode::from($row->string('stage')),
                handlerVersion: $row->string('handler_version'),
                eligibility: HandlerEligibility::from($row->string('state')),
                version: $row->int('version'),
            ),
        ], LedgerRow::all(
            'SELECT DISTINCT h.stage, w.cohort_key, h.id, h.handler_version, h.state, h.version
             FROM (VALUES '.implode(', ', $versions).') AS installed(stage, handler_version)
             JOIN prc_process_handler_holds h USING (stage, handler_version)
             JOIN prc_process_work w ON w.stage = h.stage
             WHERE '.self::ELIGIBLE_WORK,
            $bindings,
        ));
    }

    /** @param array<string, ProcessUuid> $ids */
    private function grantLocked(AuthoritySnapshot $snapshot, LockedPrefix $prefix, FairnessCursor $cursor, array $ids): AdmissionResult
    {
        $code = $snapshot->handler->stage;
        $workId = $snapshot->workId->value->value;
        $dispatchGeneration = $prefix->work->int('dispatch_generation') + 1;
        DB::insert(
            "INSERT INTO prc_admission_groups (id, work_id, dispatch_generation, required_lane_set_hash, state, admitted_at)
             VALUES (?, ?, ?, decode(?, 'hex'), 'admitted', clock_timestamp())",
            [$ids['group']->value, $workId, $dispatchGeneration, $this->policy->requiredLaneSetHash($code)->hex],
        );
        foreach ($snapshot->capacity as $authority) {
            if ($authority->kind !== CapacityKind::Lane) {
                continue;
            }
            DB::insert(
                "INSERT INTO prc_process_reservations (admission_group_id, lane_id, work_id, stage, state) VALUES (?, ?, ?, ?, 'admitted')",
                [$ids['group']->value, $authority->id->value, $workId, $code->value],
            );
        }
        $row = LedgerRow::one(
            "UPDATE prc_process_work
             SET state = 'admitted', due_at = NULL, reason_code = NULL, dispatch_generation = ?,
                 dispatch_claim_until = clock_timestamp() + make_interval(secs => ?),
                 transition_seq = transition_seq + 1, updated_at = clock_timestamp()
             WHERE id = ? AND state IN ('ready', 'deferred') AND dispatch_generation = ?
             RETURNING transition_seq",
            [$dispatchGeneration, $this->policy->claimVisibilitySeconds($code), $workId, $dispatchGeneration - 1],
        ) ?? throw new \LogicException('Admission fence did not match the locked work.');
        $parent = $prefix->lane($this->policy->parentLane($code));
        $encoded = $this->codec->encodeFairnessCursor($cursor);
        $cursorUpdated = DB::update(
            'UPDATE prc_process_lanes SET fairness_cursor = ?::jsonb, fairness_cursor_schema_version = ? WHERE id = ?',
            [$encoded->json, $encoded->schemaVersion, $parent->id],
        );
        if ($cursorUpdated !== 1) {
            throw new \LogicException('Parent fairness cursor must change with the admission.');
        }
        $this->transitions->recordWork(new TransitionData(
            eventId: $ids['event'],
            projectionMessageId: $ids['projection'],
            entityId: $snapshot->workId->value,
            entitySeq: $row->int('transition_seq'),
            previousState: $prefix->work->string('state'),
            newState: WorkState::Admitted->value,
            reasonCode: null,
            actor: ActorKind::System,
            generation: $prefix->work->int('generation'),
        ));
        $generation = new DeliveryGeneration($dispatchGeneration);
        $this->outbox->enqueueWorkWake($ids['wake'], $snapshot->workId, $generation, $code);

        return AdmissionResult::admitted($generation, $ids['wake']);
    }
}
