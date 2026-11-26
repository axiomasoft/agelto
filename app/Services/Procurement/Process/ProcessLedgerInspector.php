<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final readonly class ProcessLedgerInspector
{
    /** @return array<string, string> */
    public function openIncidents(): array
    {
        if (! Schema::hasTable('prc_process_incidents')) {
            return [];
        }

        $count = DB::table('prc_process_incidents')->where('state', 'open')->count();

        if ($count === 0) {
            return [];
        }

        return ['open_incidents' => "Открытых инцидентов (prc_process_incidents): {$count}"];
    }

    /** @return array<string, string> */
    public function blockedWork(): array
    {
        if (! Schema::hasTable('prc_process_work')) {
            return [];
        }

        $count = DB::table('prc_process_work')->where('state', 'blocked')->where('reason_code', '!=', 'paused')->count();

        if ($count === 0) {
            return [];
        }

        return ['blocked_work' => "Заблокированных работ (prc_process_work, кроме paused): {$count}"];
    }

    /** @return array<string, string> */
    public function staleOutbox(): array
    {
        if (! Schema::hasTable('prc_process_outbox')) {
            return [];
        }

        $count = DB::table('prc_process_outbox')
            ->whereNull('published_at')
            ->whereRaw("available_at < clock_timestamp() - interval '15 minutes'")
            ->count();

        if ($count === 0) {
            return [];
        }

        return ['stale_outbox' => "Неопубликованных сообщений в outbox (>15мин): {$count}"];
    }
}
