<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process\Authority;

use App\Dto\Procurement\Process\Governance\InvariantViolation;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Governance\ControlChangeStatus;
use App\Enums\Procurement\Process\Governance\ControlMode;
use App\Enums\Procurement\Process\Governance\InvariantKind;
use App\Repositories\Procurement\Process\Support\CallerTransaction;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Procurement\Process\Infrastructure\ProcessPolicy;

/** Current-core diagnostics only: no repair, member deletion or capacity increase. */
final readonly class InvariantRepository
{
    public function __construct(
        private ProcessPolicy $policy,
        private IncidentRepository $incidents,
        private ControlRepository $controls,
    ) {}

    /**
     * Controls SHARE -> all current capacity SHARE, in the same order as the execution prefix.
     * A single statement sees group/work/attempt/member facts in one MVCC snapshot. Capacity
     * writers cannot move a healthy group midway through that snapshot. Heartbeat is work-only
     * and does not change any predicate here. No work/subject/incident writes in this transaction.
     *
     * This scan checks admission lane capacity. It performs no object I/O and does not release
     * storage reservations or mutate storage-transfer ledgers.
     *
     * @return list<InvariantViolation>
     */
    public function readBatch(int $limit): array
    {
        CallerTransaction::require('Invariant read');
        if ($limit < 1 || $limit > 50) {
            throw new \InvalidArgumentException('Invariant batch must be between 1 and 50.');
        }
        LedgerRow::one("SELECT id FROM prc_control_scopes WHERE scope_key = 'system' FOR SHARE")
            ?? throw new \LogicException('Invariant scan requires the materialized system authority.');
        LedgerRow::all('SELECT id FROM prc_process_lanes ORDER BY id FOR SHARE');

        $values = [];
        $bindings = [];
        foreach (StageCode::cases() as $stage) {
            $keys = $this->policy->requiredLanes($stage);
            sort($keys, SORT_STRING);
            $values[] = '(?::text, ?::text[], ?::text)';
            array_push($bindings, $stage->value, '{'.implode(',', $keys).'}', $this->policy->requiredLaneSetHash($stage)->hex);
        }
        $required = implode(', ', $values);
        $rows = LedgerRow::all(
            <<<SQL
            WITH required(stage, lane_keys, lane_hash) AS (VALUES {$required}),
            candidate_work(id) AS (
                SELECT id FROM prc_process_work WHERE state = 'admitted'
                UNION
                SELECT id FROM prc_process_work WHERE state = 'running'
                UNION
                SELECT work_id FROM prc_admission_groups WHERE state <> 'released'
                UNION
                SELECT work_id FROM prc_process_attempts WHERE finished_at IS NULL OR terminal_kind IS NULL
            ), work_facts AS (
                SELECT w.id, w.state, w.dispatch_generation, w.generation, w.owner_token,
                       g.id AS group_id, g.state AS group_state,
                       g.dispatch_generation AS group_dispatch, g.attempt_id,
                       encode(g.required_lane_set_hash, 'hex') AS group_hash,
                       required.lane_keys, required.lane_hash,
                       ARRAY(SELECT l.lane_key FROM prc_process_reservations r
                             JOIN prc_process_lanes l ON l.id = r.lane_id
                             WHERE r.admission_group_id = g.id ORDER BY l.lane_key COLLATE "C") AS member_keys,
                       EXISTS(SELECT 1 FROM prc_process_reservations r WHERE r.admission_group_id = g.id
                              AND (r.work_id <> w.id OR r.stage <> w.stage OR r.state <> g.state)) AS bad_member,
                       a.work_id AS attempt_work, a.generation AS attempt_generation,
                       a.finished_at AS attempt_finished, a.terminal_kind AS attempt_terminal,
                       a.owner_token_fingerprint AS attempt_token,
                       (SELECT count(*) FROM prc_process_attempts p
                        WHERE p.work_id = w.id AND p.finished_at IS NULL) AS unfinished,
                       EXISTS(SELECT 1 FROM prc_process_attempts p WHERE p.work_id = w.id
                              AND ((p.finished_at IS NULL) <> (p.terminal_kind IS NULL))) AS bad_attempt
                FROM candidate_work c
                JOIN prc_process_work w ON w.id = c.id
                JOIN required ON required.stage = w.stage
                LEFT JOIN prc_admission_groups g ON g.work_id = w.id AND g.state <> 'released'
                LEFT JOIN prc_process_attempts a ON a.id = g.attempt_id
            ), violations AS (
                SELECT 'work:' || f.id AS scope_key, v.kind
                FROM work_facts f CROSS JOIN LATERAL (VALUES
                    ('invariant_group_membership', f.group_id IS NOT NULL AND
                        (f.group_hash <> f.lane_hash OR f.member_keys <> f.lane_keys OR f.bad_member)),
                    ('invariant_dispatch_generation', f.group_id IS NOT NULL AND f.group_dispatch <> f.dispatch_generation),
                    ('invariant_work_state', ((f.state IN ('admitted', 'running')) <> (f.group_id IS NOT NULL))
                        OR (f.group_id IS NOT NULL AND f.group_state <> f.state)),
                    ('invariant_attempt_state', f.bad_attempt OR
                        (f.state <> 'running' AND (f.unfinished <> 0 OR f.attempt_id IS NOT NULL)) OR
                        (f.state = 'running' AND (f.unfinished <> 1 OR f.attempt_id IS NULL
                            OR f.attempt_work IS DISTINCT FROM f.id
                            OR f.attempt_generation IS DISTINCT FROM f.generation
                            OR f.attempt_finished IS NOT NULL OR f.attempt_terminal IS NOT NULL
                            OR f.attempt_token IS DISTINCT FROM sha256(convert_to(f.owner_token::text, 'UTF8')))))
                ) AS v(kind, violated) WHERE v.violated
                UNION ALL
                SELECT 'group:' || g.id, 'invariant_released_members'
                FROM (SELECT DISTINCT admission_group_id FROM prc_process_reservations) r
                JOIN prc_admission_groups g ON g.id = r.admission_group_id
                WHERE g.state = 'released'
                UNION ALL
                SELECT 'lane:' || l.id, v.kind
                FROM prc_process_lanes l CROSS JOIN LATERAL (
                    SELECT count(*) AS outstanding, count(*) FILTER (WHERE r.state = 'running') AS running
                    FROM prc_process_reservations r WHERE r.lane_id = l.id
                ) counts CROSS JOIN LATERAL (VALUES
                    ('invariant_lane_outstanding', counts.outstanding > l.max_outstanding),
                    ('invariant_lane_running', counts.running > l.max_running)
                ) AS v(kind, violated) WHERE v.violated
            )
            SELECT scope_key, kind FROM violations v
            WHERE NOT EXISTS (
                SELECT 1 FROM prc_process_incidents i
                WHERE i.scope_key = v.scope_key AND i.kind = v.kind AND i.state <> 'resolved'
                  AND (SELECT desired_mode FROM prc_control_scopes WHERE scope_key = 'system') = 'paused'
            )
            ORDER BY scope_key COLLATE "C", kind COLLATE "C" LIMIT ?
            SQL,
            [...$bindings, $limit],
        );

        return array_map(static fn (LedgerRow $row): InvariantViolation => new InvariantViolation(
            InvariantKind::from($row->string('kind')), $row->string('scope_key'),
        ), $rows);
    }

    /**
     * Separate mutation AFTER read commit. System UPDATE is the first row lock: no SHARE upgrade.
     * Incident+admission pause+both journals/outboxes commit together, or all roll back. Existing
     * manual pause is preserved; concurrent scanners produce one incident/event and one pause.
     */
    public function recordAndHold(
        InvariantViolation $violation,
        ProcessUuid $incidentId,
        ProcessUuid $incidentEventId,
        ProcessUuid $incidentProjectionId,
        ProcessUuid $controlEventId,
        ProcessUuid $controlProjectionId,
    ): bool {
        CallerTransaction::require('Invariant incident and hold');
        $control = LedgerRow::one("SELECT control_version FROM prc_control_scopes WHERE scope_key = 'system' FOR UPDATE")
            ?? throw new \LogicException('Invariant hold requires the materialized system authority.');
        $change = $this->controls->setMode(
            'system', ControlMode::Paused, null, 'process_invariant:'.$violation->kind->value,
            $control->int('control_version'), $controlEventId, $controlProjectionId,
        );
        if (! in_array($change->status, [ControlChangeStatus::Applied, ControlChangeStatus::Unchanged], true)) {
            throw new \LogicException('Invariant hold lost its locked control fence.');
        }
        // Locking system first also serializes scanners across different findings. Known
        // incidents are durable progress: repeated bad rows cannot starve later batches/restarts.
        $existing = LedgerRow::one(
            "SELECT id FROM prc_process_incidents WHERE fingerprint = decode(?, 'hex') AND state <> 'resolved'",
            [$violation->fingerprint()->hex],
        );
        if ($existing !== null) {
            return $change->status === ControlChangeStatus::Applied;
        }
        $this->incidents->open(
            $violation->fingerprint(), $violation->scopeKey, $violation->kind->value,
            ['subject' => $violation->scopeKey, 'predicate' => $violation->kind->value, 'policy_version' => $this->policy->policyVersion],
            $incidentId, $incidentEventId, $incidentProjectionId,
        );

        return true;
    }
}
