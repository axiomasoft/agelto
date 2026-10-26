<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Dto\Procurement\Process\AdmissionResult;
use App\Dto\Procurement\Process\AuthoritySnapshot;
use App\Dto\Procurement\Process\CapacityAuthority;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ControlSnapshot;
use App\Dto\Procurement\Process\DeliveryGeneration;
use App\Dto\Procurement\Process\FairnessCursor;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\ActorKind;
use App\Enums\Procurement\Process\AdmissionStatus;
use App\Enums\Procurement\Process\AuthorityGap;
use App\Enums\Procurement\Process\PrefixFailure;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\WorkState;
use App\Services\Procurement\Process\ProcessPayloadCodec;
use App\Services\Procurement\Process\ProcessPolicy;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Process\SubjectLockResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Admission ready/due work в admitted (bp-sql-admit, execution#s05/#s11). Одна короткая
 * ProcessTransaction на work: полный prefix с SKIP LOCKED только на work, authoritative COUNT
 * active reservation rows после всех capacity locks, cohort cap и fairness cursor parent lane
 * повторно сверяются под теми же locks. Grant атомарно вставляет group + точный набор members,
 * увеличивает dispatch_generation, пишет transition + projection intent, work_wake и cursor.
 */
final readonly class AdmissionRepository
{
    private const int MAX_RELOADS = 3;

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
            'group' => self::uuid(), 'event' => self::uuid(), 'projection' => self::uuid(), 'wake' => self::uuid(),
            'incident' => self::uuid(), 'incident_event' => self::uuid(), 'incident_projection' => self::uuid(),
        ];
        for ($reload = 0; $reload < self::MAX_RELOADS; $reload++) {
            $snapshot = $this->authority->readAuthoritySnapshot($workId);
            if ($snapshot instanceof AuthorityGap) {
                return AdmissionResult::refused(in_array($snapshot, [AuthorityGap::HandlerUnregistered, AuthorityGap::HandlerAuthorityMissing], true)
                    ? AdmissionStatus::HandlerUnavailable
                    : AdmissionStatus::NotEligible);
            }
            $eligibility = $this->readEligibilitySnapshots($snapshot);
            $result = $this->transaction->run(fn (): AdmissionResult => $this->admitLocked($snapshot, $expectedCursor, $eligibleCohorts, $ids, $eligibility));
            if ($result->status === AdmissionStatus::InvariantViolation) {
                $this->openMembershipIncident($snapshot, $ids);
            }
            if ($result->status !== AdmissionStatus::SnapshotChanged) {
                return $result;
            }
        }

        return AdmissionResult::refused(AdmissionStatus::SnapshotChanged);
    }

    /**
     * Unlocked выбор: (stage, cohort) с eligible work = ready либо deferred с наступившим due.
     *
     * @param  list<StageCode>  $stages
     * @return list<array{stage: StageCode, cohort: string}>
     */
    public function eligiblePairs(array $stages): array
    {
        $values = array_map(static fn (StageCode $code): string => $code->value, $stages);

        return array_map(static fn (LedgerRow $row): array => ['stage' => StageCode::from($row->string('stage')), 'cohort' => $row->string('cohort_key')], LedgerRow::all(
            'SELECT stage, cohort_key FROM prc_process_work
             WHERE stage IN ('.LedgerRow::placeholders(count($values)).") AND (state = 'ready' OR (state = 'deferred' AND due_at <= clock_timestamp()))
             GROUP BY stage, cohort_key ORDER BY cohort_key COLLATE \"C\", stage COLLATE \"C\"",
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
            "WITH cutoff AS (SELECT clock_timestamp() - make_interval(secs => ?) AS aged_before)
             SELECT w.id FROM prc_process_work w CROSS JOIN cutoff c
             WHERE w.stage = ? AND w.cohort_key = ? AND (w.state = 'ready' OR (w.state = 'deferred' AND w.due_at <= clock_timestamp()))
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
     * @param  array<string, AuthoritySnapshot>  $eligibility
     */
    private function admitLocked(AuthoritySnapshot $snapshot, FairnessCursor $expectedCursor, int $eligibleCohorts, array $ids, array $eligibility): AdmissionResult
    {
        $prefix = $this->authority->lockPrefix($snapshot, skipLockedWork: true, eligibilitySnapshots: array_values($eligibility));
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

    /** @return array<string, AuthoritySnapshot> One representative per ready/due pair, for early authority locks only. */
    private function readEligibilitySnapshots(AuthoritySnapshot $candidate): array
    {
        $snapshots = [];
        foreach ($this->parentEligiblePairs($candidate) as $pair) {
            $work = LedgerRow::one(
                "SELECT id FROM prc_process_work WHERE stage = ? AND cohort_key = ?
                 AND (state = 'ready' OR (state = 'deferred' AND due_at <= clock_timestamp())) ORDER BY id LIMIT 1",
                [$pair['stage']->value, $pair['cohort']],
            );
            if ($work === null) {
                continue;
            }
            $snapshot = $this->authority->readAuthoritySnapshot(new WorkId(new ProcessUuid($work->string('id'))));
            if ($snapshot instanceof AuthoritySnapshot) {
                $snapshots[$pair['stage']->value.':'.$pair['cohort']] = $snapshot;
            }
        }

        return $snapshots;
    }

    /**
     * Fresh ready/due pairs + early-locked controls/handlers/capacity; caller count is never authority.
     *
     * @param  array<string, AuthoritySnapshot>  $eligibility
     */
    private function lockedEligibleCohorts(AuthoritySnapshot $candidate, LockedPrefix $prefix, array $eligibility): ?int
    {
        $cohorts = [];
        foreach ($this->parentEligiblePairs($candidate) as $pair) {
            $snapshot = $eligibility[$pair['stage']->value.':'.$pair['cohort']] ?? null;
            if ($snapshot === null) {
                return null;
            }
            $keys = array_map(static fn (ControlSnapshot $scope): string => $scope->scopeKey, $snapshot->controls);
            if (array_diff($keys, $prefix->enabledScopeKeys) !== [] || ! in_array($snapshot->handler->id->value, $prefix->healthyHandlerIds, true)) {
                continue;
            }
            foreach ($this->policy->requiredLanes($pair['stage']) as $key) {
                $lane = $prefix->lane($key);
                if ($lane->outstanding >= $lane->maxOutstanding) {
                    continue 2;
                }
            }
            $cohorts[$pair['cohort']] = true;
        }

        return count($cohorts);
    }

    /** @return list<array{stage: StageCode, cohort: string}> Same installed-handler boundary as feeder selection. */
    private function parentEligiblePairs(AuthoritySnapshot $candidate): array
    {
        $stages = array_values(array_filter(
            $this->policy->stagesOfParentLane($this->policy->parentLane($candidate->handler->stage)),
            fn (StageCode $stage): bool => $this->registry->handlerVersion($stage) !== null,
        ));

        return array_values(array_filter($this->eligiblePairs($stages), function (array $pair): bool {
            return LedgerRow::one('SELECT id FROM prc_process_handler_holds WHERE stage = ? AND handler_version = ?', [
                $pair['stage']->value, $this->registry->handlerVersion($pair['stage']),
            ]) !== null;
        }));
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
        $this->transitions->recordWork(
            $ids['event'], $ids['projection'], $snapshot->workId->value, $row->int('transition_seq'),
            $prefix->work->string('state'), WorkState::Admitted->value, null, ActorKind::System, $prefix->work->int('generation'),
        );
        $generation = new DeliveryGeneration($dispatchGeneration);
        $this->outbox->enqueueWorkWake($ids['wake'], $snapshot->workId, $generation, $code);

        return AdmissionResult::admitted($generation, $ids['wake']);
    }

    /** @param array<string, ProcessUuid> $ids */
    private function openMembershipIncident(AuthoritySnapshot $snapshot, array $ids): void
    {
        $lanes = array_map(static fn (CapacityAuthority $authority): string => $authority->id->value, $snapshot->capacity);
        $this->transaction->run(fn (): ProcessUuid => $this->incidents->open(
            fingerprint: ContentHash::of('admission-membership:v1:'.$snapshot->workId->value->value),
            scopeKey: 'lane:'.$this->policy->parentLane($snapshot->handler->stage),
            kind: 'admission_membership_mismatch',
            evidence: ['work_id' => $snapshot->workId->value->value, 'required_lanes' => implode(',', $lanes)],
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
