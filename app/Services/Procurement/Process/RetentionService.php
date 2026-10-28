<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\PruneResult;
use App\Repositories\Procurement\Process\LedgerRow;
use DateTimeImmutable;

/**
 * Политика выбора устаревших transition events (observability#s07); удаляет только
 * ProjectionRetention, который сам проверяет receipts и fanout. Событие кандидат, если оно старше
 * срока и сущность не нуждается в истории: активные/нерешённые incidents, нетерминальные work,
 * runs с незакрытым discovery или нетерминальным required work, а также ПОСЛЕДНЕЕ transition
 * каждой сущности (tombstone текущего состояния) не трогаются. Operator audit живёт по audit_days.
 * Выбор bounded и детерминирован (occurred_at, event_id): повтор после crash безопасен.
 */
final readonly class RetentionService
{
    private const string TERMINAL_WORK = "('succeeded','failed_item','cancelled','superseded')";

    public function __construct(private RetentionPolicy $policy, private ProjectionPolicy $projection, private ProjectionRetention $retention) {}

    /** @return list<ProcessUuid> */
    public function candidates(DateTimeImmutable $now, ?int $limit = null): array
    {
        $limit ??= $this->projection->pruneBatch;
        $cutoff = $now->modify('-'.$this->policy->transitionDays.' days')->format('Y-m-d H:i:sP');
        $auditCutoff = $now->modify('-'.$this->policy->auditDays.' days')->format('Y-m-d H:i:sP');
        $terminalWork = self::TERMINAL_WORK;
        $rows = LedgerRow::all(
            "SELECT t.event_id FROM prc_process_transitions t
             WHERE t.occurred_at < ?::timestamptz
               AND (t.actor_kind <> 'operator' OR t.occurred_at < ?::timestamptz)
               AND (
                 (t.entity_kind = 'work' AND EXISTS (SELECT 1 FROM prc_process_work w WHERE w.id = t.work_id AND w.state IN {$terminalWork})
                   AND EXISTS (SELECT 1 FROM prc_process_transitions n WHERE n.work_id = t.work_id AND n.entity_seq > t.entity_seq))
                 OR (t.entity_kind = 'incident' AND EXISTS (SELECT 1 FROM prc_process_incidents i WHERE i.id = t.incident_id AND i.state = 'resolved')
                   AND EXISTS (SELECT 1 FROM prc_process_transitions n WHERE n.incident_id = t.incident_id AND n.entity_seq > t.entity_seq))
                 OR (t.entity_kind = 'control' AND EXISTS (SELECT 1 FROM prc_process_transitions n WHERE n.control_id = t.control_id AND n.entity_seq > t.entity_seq))
                 OR (t.entity_kind = 'run' AND EXISTS (SELECT 1 FROM prc_process_runs r WHERE r.id = t.run_id AND r.discovery_outcome <> 'collecting')
                   AND NOT EXISTS (SELECT 1 FROM prc_process_run_work rw JOIN prc_process_work w ON w.id = rw.work_id
                                   WHERE rw.run_id = t.run_id AND rw.accounting_role = 'required' AND w.state NOT IN {$terminalWork})
                   AND EXISTS (SELECT 1 FROM prc_process_transitions n WHERE n.run_id = t.run_id AND n.entity_seq > t.entity_seq))
               )
             ORDER BY t.occurred_at, t.event_id
             LIMIT ?",
            [$cutoff, $auditCutoff, $limit],
        );

        return array_map(static fn (LedgerRow $row): ProcessUuid => new ProcessUuid($row->string('event_id')), $rows);
    }

    /** Один bounded batch: выбранные events удаляются, только если их projections подтверждены. */
    public function prune(DateTimeImmutable $now): PruneResult
    {
        $candidates = $this->candidates($now);

        return $candidates === [] ? new PruneResult(0, 0, false) : $this->retention->prune($candidates);
    }
}
