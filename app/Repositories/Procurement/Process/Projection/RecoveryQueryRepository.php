<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process\Projection;

use App\Dto\Procurement\Process\Authority\AuthoritySnapshot;
use App\Dto\Procurement\Process\Authority\ScopeDefinition;
use App\Dto\Procurement\Process\Outcomes\WorkCheckpointEnvelope;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\VersionedPayload;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Dto\Procurement\Process\Provider\ProviderRecoveryPlan;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Outcomes\BlockedReason;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Repositories\Procurement\Process\Support\LockedPrefix;
use App\Services\Procurement\Process\Execution\StageRegistry;
use App\Services\Procurement\Process\Infrastructure\ProcessPolicy;

/**
 * Candidate selectors, bounded cursor pagination и read/hydration helpers для RecoveryRepository.
 */
final readonly class RecoveryQueryRepository
{
    public function __construct(
        private StageRegistry $registry,
        private ProcessPolicy $policy,
    ) {}

    /**
     * Expired running: lease истёк минимум 2 s назад; locked решение — lease_until <= now.
     *
     * @param  array{sort: string, id: string}|null  $cursor
     * @return list<WorkId>
     */
    public function expiredRunning(int $limit, ?array &$cursor = null): array
    {
        return $this->selectWorkIds(
            where: "state = 'running' AND lease_until < clock_timestamp() - interval '2 seconds'",
            sortColumn: 'lease_until',
            bindings: [],
            limit: $limit,
            cursor: $cursor,
        );
    }

    /**
     * Admitted с истёкшей delivery либо под committed pause/held executing handler.
     *
     * @param  array{sort: string, id: string}|null  $cursor
     * @return list<WorkId>
     */
    public function admittedCandidates(int $limit, ?array &$cursor = null): array
    {
        [$control, $bindings] = $this->controlPredicate();

        return $this->selectWorkIds(
            where: "state = 'admitted' AND (dispatch_claim_until <= clock_timestamp() OR {$control})",
            sortColumn: 'dispatch_claim_until',
            bindings: $bindings,
            limit: $limit,
            cursor: $cursor,
        );
    }

    /**
     * Blocked(paused|handler_hold), чьи scopes сейчас не paused и executing handler не held.
     * Pause/held-пары исключены в SQL, поэтому постоянно paused work не занимает batch.
     *
     * @param  array{sort: string, id: string}|null  $cursor
     * @return list<WorkId>
     */
    public function releasableBlocked(int $limit, ?array &$cursor = null): array
    {
        [$control, $bindings] = $this->controlPredicate();

        return $this->selectWorkIds(
            where: "state = 'blocked' AND reason_code IN ('paused', 'handler_hold') AND NOT ({$control})",
            sortColumn: 'updated_at',
            bindings: $bindings,
            limit: $limit,
            cursor: $cursor,
        );
    }

    /**
     * Cancelled work с pinned provider plan. Due у cancelled запрещён схемой, поэтому очередь — по
     * updated_at: reschedule переставляет в конец.
     *
     * @param  array{sort: string, id: string}|null  $cursor
     * @return list<WorkId>
     */
    public function cancelledProvider(int $limit, ?array &$cursor = null): array
    {
        return $this->selectWorkIds(
            where: "state = 'cancelled' AND checkpoint->'provider_recovery_plan'->>'variant' = 'provider'",
            sortColumn: 'updated_at',
            bindings: [],
            limit: $limit,
            cursor: $cursor,
        );
    }

    /**
     * selector: provider_recovery_due_at, а не lease_until/due_at; earliest-due fairness.
     *
     * @param  array{sort: string, id: string}|null  $cursor
     * @return list<WorkId>
     */
    public function blockedProvider(int $limit, ?array &$cursor = null): array
    {
        return $this->selectWorkIds(
            where: "state = 'blocked' AND reason_code = 'provider_result_unknown' AND provider_recovery_due_at <= clock_timestamp()",
            sortColumn: 'provider_recovery_due_at',
            bindings: [],
            limit: $limit,
            cursor: $cursor,
        );
    }

    /**
     * Durable unchecked fault evidence: окно отсчитывается от finished_at кандидата, а не от now.
     *
     * @param  array{sort: string, id: string}|null  $cursor
     * @return list<array{attempt_id: string, work_id: string, handler_version: string, signature: string, finished_at: string, stage: StageCode}>
     */
    public function uncheckedFaults(int $limit, ?array &$cursor = null): array
    {
        $rows = $this->selectCursorRows(
            select: "SELECT a.id AS attempt_id, a.work_id::text AS work_id, a.handler_version, encode(a.fault_signature, 'hex') AS signature, a.finished_at::text AS finished_at, a.finished_at::text AS cursor_sort, w.stage
                     FROM prc_process_attempts a JOIN prc_process_work w ON w.id = a.work_id",
            where: 'a.fault_signature IS NOT NULL AND a.finished_at IS NOT NULL AND a.fault_policy_checked_at IS NULL',
            sortColumn: 'a.finished_at',
            idColumn: 'a.id',
            bindings: [],
            limit: $limit,
            cursor: $cursor,
        );
        $candidates = [];
        foreach ($rows as $row) {
            $candidates[] = [
                'attempt_id' => $row->string('attempt_id'),
                'work_id' => $row->string('work_id'),
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
    public function pinnedPlan(WorkId|LedgerRow $work): ?array
    {
        $row = $work instanceof LedgerRow ? $work : LedgerRow::one(
            'SELECT checkpoint::text AS checkpoint, checkpoint_schema_version, checkpoint_seq FROM prc_process_work WHERE id = ?',
            [$work->value->value],
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

    public function runningAttempt(WorkId $workId, int $generation): ?LedgerRow
    {
        return LedgerRow::one(
            'SELECT id, handler_version FROM prc_process_attempts WHERE work_id = ? AND generation = ? AND finished_at IS NULL',
            [$workId->value->value, $generation],
        );
    }

    public function providerRecoveryDue(WorkId $workId): bool
    {
        $due = LedgerRow::one('SELECT provider_recovery_due_at <= clock_timestamp() AS due FROM prc_process_work WHERE id = ?', [$workId->value->value]);

        return $due !== null && $due->bool('due');
    }

    public function lockFaultAuthorities(StageCode $code, string $version): LedgerRow
    {
        $keys = array_map(static fn (ScopeDefinition $scope): string => $scope->key, $this->policy->stageScopes($code));
        sort($keys, SORT_STRING);
        $controls = LedgerRow::all(
            'SELECT id FROM prc_control_scopes WHERE scope_key IN ('.LedgerRow::placeholders(count($keys)).') ORDER BY scope_key COLLATE "C" FOR SHARE',
            $keys,
        );
        if (count($controls) !== count($keys)) {
            throw new \LogicException('Control scopes of the stage are not materialized: '.$code->value);
        }
        $hold = LedgerRow::one(
            'SELECT id, state FROM prc_process_handler_holds WHERE stage = ? AND handler_version = ? FOR UPDATE',
            [$code->value, $version],
        );
        if ($hold === null) {
            throw new \LogicException('Historical handler authority is missing: '.$code->value.':'.$version);
        }

        return $hold;
    }

    public function distinctFaultInputs(StageCode $code, string $version, string $signature, string $finishedAt, int $windowMinutes): int
    {
        return (LedgerRow::one(
            "SELECT count(DISTINCT w.input_hash) AS inputs
             FROM prc_process_attempts a JOIN prc_process_work w ON w.id = a.work_id
             WHERE w.stage = ? AND a.handler_version = ? AND a.fault_signature = decode(?, 'hex') AND a.finished_at IS NOT NULL
                AND a.finished_at <= ?::timestamptz AND a.finished_at >= ?::timestamptz - make_interval(mins => ?)",
            [$code->value, $version, $signature, $finishedAt, $finishedAt, $windowMinutes],
        ) ?? throw new \LogicException('Aggregate query always returns a row.'))->int('inputs');
    }

    public function holdReason(LockedPrefix $prefix): ?BlockedReason
    {
        return match (true) {
            ! $prefix->controlsEnabled => BlockedReason::Paused,
            ! $prefix->handlerHealthy => BlockedReason::HandlerHold,
            default => null,
        };
    }

    /** Metadata verified outside locks must still identify the exact pinned operation under locks. */
    public static function sameOperation(ProviderRecoveryPlan $pinned, ProviderRecoveryPlan $finding): bool
    {
        return $pinned->logicalRequestId->value === $finding->logicalRequestId->value
            && $pinned->physicalCallId->value === $finding->physicalCallId->value
            && $pinned->purpose === $finding->purpose
            && $pinned->expectedInputHash->hex === $finding->expectedInputHash->hex
            && $pinned->recipeHash->hex === $finding->recipeHash->hex
            && $pinned->requestEpoch === $finding->requestEpoch
            && $pinned->planVersion === $finding->planVersion;
    }

    /** Envelope сохраняет stage progress; работе без checkpoint даётся initial progress её codec. */
    public function envelope(AuthoritySnapshot $snapshot, ?WorkCheckpointEnvelope $current, ProviderRecoveryPlan $plan): VersionedPayload
    {
        $progress = $current->stageProgress ?? $this->registry->initialProgress($snapshot->handler->stage);

        return $this->registry->payloadCodec()->encodeCheckpoint(new WorkCheckpointEnvelope($progress, $plan));
    }

    /**
     * SQL-предикат work, чья control closure содержит paused scope либо чья executing handler
     * version held; 'FALSE' — если таких нет. Выводится из policy scope keys без сканирования prc_process_work.
     *
     * @return array{string, list<string>}
     */
    private function controlPredicate(): array
    {
        $paused = array_map(
            static fn (LedgerRow $row): string => $row->string('scope_key'),
            LedgerRow::all("SELECT scope_key FROM prc_control_scopes WHERE desired_mode = 'paused'"),
        );
        if (in_array('system', $paused, true)) {
            return ['TRUE', []];
        }
        $pausedSet = array_fill_keys($paused, true);
        $held = $this->heldStages();
        $cohorts = $this->extractPausedCohorts($paused);
        $pairs = $this->collectPausedStageCohortPairs($pausedSet, $cohorts, $held);

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

    /** @return array<string, true> */
    private function heldStages(): array
    {
        $held = [];
        foreach (LedgerRow::all("SELECT stage, handler_version FROM prc_process_handler_holds WHERE state = 'held'") as $row) {
            $code = StageCode::tryFrom($row->string('stage'));
            if ($code !== null && ($this->registry->handlerVersion($code) ?? $row->string('handler_version')) === $row->string('handler_version')) {
                $held[$code->value] = true;
            }
        }

        return $held;
    }

    /**
     * @param  list<string>  $paused
     * @return list<string>
     */
    private function extractPausedCohorts(array $paused): array
    {
        $cohorts = [];
        foreach ($paused as $scopeKey) {
            $cohort = $this->policy->cohortFromScopeKey($scopeKey);
            if ($cohort !== null) {
                $cohorts[] = $cohort;
            }
        }

        return array_values(array_unique($cohorts));
    }

    /**
     * @param  array<string, true>  $pausedSet
     * @param  list<string>  $cohorts
     * @param  array<string, true>  $held
     * @return list<array{0: string, 1: string}>
     */
    private function collectPausedStageCohortPairs(array $pausedSet, array $cohorts, array &$held): array
    {
        $pairs = [];
        foreach (StageCode::cases() as $code) {
            if ($this->hasPausedScope($this->policy->stageScopes($code), $pausedSet)) {
                $held[$code->value] = true;

                continue;
            }
            foreach ($cohorts as $cohort) {
                if ($this->hasPausedScope($this->policy->workScopes($code, $cohort), $pausedSet)) {
                    $pairs[] = [$code->value, $cohort];
                }
            }
        }

        return $pairs;
    }

    /**
     * @param  list<ScopeDefinition>  $scopes
     * @param  array<string, true>  $pausedSet
     */
    private function hasPausedScope(array $scopes, array $pausedSet): bool
    {
        foreach ($scopes as $scope) {
            if (isset($pausedSet[$scope->key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $bindings
     * @param  array{sort: string, id: string}|null  $cursor
     * @return list<WorkId>
     */
    private function selectWorkIds(string $where, string $sortColumn, array $bindings, int $limit, ?array &$cursor = null): array
    {
        return self::ids($this->selectCursorRows(
            select: "SELECT id, {$sortColumn}::text AS cursor_sort FROM prc_process_work",
            where: $where,
            sortColumn: $sortColumn,
            idColumn: 'id',
            bindings: $bindings,
            limit: $limit,
            cursor: $cursor,
        ));
    }

    /**
     * Bounded выборка с курсором и wrap-around: если выборка после курсора вернула меньше $limit
     * строк, в этом же вызове добирается начало очереди до $limit (без дублирования строк), чтобы
     * ни неразрешимый префикс не блокировал хвост, ни короткий хвост не скрывал ошибки префикса.
     *
     * @param  list<string>  $bindings
     * @param  array{sort: string, id: string}|null  $cursor
     * @return list<LedgerRow>
     */
    private function selectCursorRows(
        string $select,
        string $where,
        string $sortColumn,
        string $idColumn,
        array $bindings,
        int $limit,
        ?array &$cursor = null,
    ): array {
        $idKey = $idColumn === 'a.id' ? 'attempt_id' : 'id';
        if ($cursor === null) {
            $rows = LedgerRow::all(
                "{$select} WHERE {$where} ORDER BY {$sortColumn}, {$idColumn} LIMIT ?",
                [...$bindings, $limit],
            );
            if (count($rows) === $limit && $rows !== []) {
                $last = $rows[array_key_last($rows)];
                $cursor = ['sort' => $last->string('cursor_sort'), 'id' => $last->string($idKey)];
            }

            return $rows;
        }

        $tail = LedgerRow::all(
            "{$select} WHERE {$where} AND ({$sortColumn}, {$idColumn}) > (?::timestamptz, ?::uuid) ORDER BY {$sortColumn}, {$idColumn} LIMIT ?",
            [...$bindings, $cursor['sort'], $cursor['id'], $limit],
        );
        if (count($tail) === $limit && $tail !== []) {
            $last = $tail[array_key_last($tail)];
            $cursor = ['sort' => $last->string('cursor_sort'), 'id' => $last->string($idKey)];

            return $tail;
        }

        $remaining = $limit - count($tail);
        $head = $remaining > 0
            ? LedgerRow::all(
                "{$select} WHERE {$where} ORDER BY {$sortColumn}, {$idColumn} LIMIT ?",
                [...$bindings, $remaining],
            )
            : [];
        if (count($head) === $remaining && $head !== []) {
            $last = $head[array_key_last($head)];
            $cursor = ['sort' => $last->string('cursor_sort'), 'id' => $last->string($idKey)];
        } else {
            $cursor = null;
        }
        $seen = [];
        $combined = [];
        foreach ([...$tail, ...$head] as $row) {
            $id = $row->string($idKey);
            if (! isset($seen[$id])) {
                $seen[$id] = true;
                $combined[] = $row;
            }
        }

        return $combined;
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
