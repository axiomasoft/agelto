<?php

declare(strict_types=1);

namespace App\Console\Commands\Process;

use App\Repositories\Procurement\Process\LedgerRow;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Read-only scheduled report; thresholds do not trigger external notifications. */
#[Signature('process:health-report')]
final class ProcessHealthReportCommand extends Command
{
    public function handle(): int
    {
        $rows = LedgerRow::all("SELECT stage,state,reason_code,count(*) AS total
            FROM prc_process_work WHERE state NOT IN ('succeeded','failed_item','cancelled','superseded')
            GROUP BY stage,state,reason_code ORDER BY stage,state,reason_code");
        foreach ($rows as $row) {
            $this->line($row->string('stage').' '.$row->string('state').' '.($row->nullableString('reason_code') ?? 'eligible').' '.$row->int('total'));
        }

        return self::SUCCESS;
    }
}
