<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Historical CLI alias retained until the operator UI cutover; Process owns all status. */
#[Signature('crawler:progress {--stalled-minutes=60 : Retained compatibility option; Process reports explicit blocked/deferred reasons}')]
final class CrawlerProgress extends Command
{
    public function handle(): int
    {
        $rows = DB::table('prc_process_work')
            ->whereIn('stage', ['listing', 'detail', 'status_refresh'])
            ->select('platform_id', 'stage', 'state', 'reason_code')->selectRaw('count(*) AS works, min(due_at) AS next_due_at')
            ->groupBy('platform_id', 'stage', 'state', 'reason_code')->orderBy('platform_id')->orderBy('stage')->get();
        foreach ($rows as $row) {
            $this->line(json_encode($row, JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
