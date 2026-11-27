<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process\Projection;

use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Repositories\Procurement\Process\Support\LedgerRow;

/** Read-only выборки ledger для correlation и operator read model; ничего не пишет и не блокирует. */
final readonly class RunReadRepository
{
    /**
     * Origin run и cohort work для correlation attempt: источник — БД, не Redis payload.
     *
     * @return array{run_id: ?ProcessUuid, cohort_key: string}|null null — work не найдена
     */
    public function correlationFor(WorkId $workId): ?array
    {
        $row = LedgerRow::one(
            "SELECT w.origin_run_id,
                    CASE WHEN p.code IS NOT NULL AND w.cohort_key = ('platform:' || w.platform_id::text) THEN 'platform:' || p.code ELSE w.cohort_key END AS cohort_key
             FROM prc_process_work w LEFT JOIN prc_platforms p ON p.id = w.platform_id
             WHERE w.id = ?",
            [$workId->value->value],
        );
        if ($row === null) {
            return null;
        }
        $runId = $row->nullableString('origin_run_id');

        return ['run_id' => $runId === null ? null : new ProcessUuid($runId), 'cohort_key' => $row->string('cohort_key')];
    }

    /**
     * Header run (discovery contract, факт старта, explicit cancel) и links, сгруппированные по ТЕКУЩЕМУ
     * состоянию work и accounting_role, ОДНИМ statement (один snapshot): reducer не смешивает cancel флаг
     * более раннего чтения с work states более позднего. Одна work может быть нужна нескольким runs; read
     * relation не удваивает executed, `created` — только relation=created. Строки без links (`state` NULL)
     * — run без work.
     *
     * @return array{header: array{outcome: string, started: bool, cancelled: bool}, groups: list<array{state: string, role: string, links: int, created: int}>}|null null — run не найден
     */
    public function runSnapshot(ProcessUuid $runId): ?array
    {
        $rows = LedgerRow::all(
            "SELECT r.discovery_outcome, r.started_at IS NOT NULL AS started, r.cancel_requested_at IS NOT NULL AS cancelled,
                    g.state, g.accounting_role, g.links, g.created
             FROM prc_process_runs r
             LEFT JOIN (
                 SELECT rw.run_id, w.state, rw.accounting_role, count(*) AS links,
                        count(*) FILTER (WHERE rw.relation = 'created') AS created
                 FROM prc_process_run_work rw JOIN prc_process_work w ON w.id = rw.work_id
                 WHERE rw.run_id = ? GROUP BY rw.run_id, w.state, rw.accounting_role
             ) g ON g.run_id = r.id
             WHERE r.id = ?",
            [$runId->value, $runId->value],
        );
        if ($rows === []) {
            return null;
        }
        $groups = [];
        foreach ($rows as $row) {
            if ($row->nullableString('state') === null) {
                continue;
            }
            $groups[] = [
                'state' => $row->string('state'),
                'role' => $row->string('accounting_role'),
                'links' => $row->int('links'),
                'created' => $row->int('created'),
            ];
        }

        return [
            'header' => ['outcome' => $rows[0]->string('discovery_outcome'), 'started' => $rows[0]->bool('started'), 'cancelled' => $rows[0]->bool('cancelled')],
            'groups' => $groups,
        ];
    }
}
