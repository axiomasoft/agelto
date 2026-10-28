<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Process;

use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\WorkId;

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
        $row = LedgerRow::one('SELECT origin_run_id, cohort_key FROM prc_process_work WHERE id = ?', [$workId->value->value]);
        if ($row === null) {
            return null;
        }
        $runId = $row->nullableString('origin_run_id');

        return ['run_id' => $runId === null ? null : new ProcessUuid($runId), 'cohort_key' => $row->string('cohort_key')];
    }
}
