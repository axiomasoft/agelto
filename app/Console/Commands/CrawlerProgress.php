<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Crawler\SourceCircuit;
use App\Models\Procurement\Crawler\SourceIncident;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

#[Signature('crawler:progress {--stalled-minutes=60 : Alert threshold for paused or held work}')]
final class CrawlerProgress extends Command
{
    public function handle(): int
    {
        $runs = (new Run)->getTable();
        $crawlers = (new Crawler)->getTable();
        $registries = (new Registry)->getTable();
        $threshold = now()->subMinutes(max(1, (int) $this->option('stalled-minutes')));
        $rows = DB::table("{$runs} as runs")->join("{$crawlers} as crawlers", 'crawlers.id', '=', 'runs.crawler_id')
            ->join("{$registries} as registries", 'registries.id', '=', 'crawlers.registry_id')
            ->selectRaw("registries.platform_id, count(*) FILTER (WHERE runs.status = 'paused') AS paused, min(COALESCE(runs.last_heartbeat_at, runs.started_at, runs.created_at)) FILTER (WHERE runs.status = 'paused') AS oldest_paused, max(runs.finished_at) FILTER (WHERE runs.status = 'completed') AS last_completed, max((runs.stats->>'listing_last_success_at')::timestamptz) AS last_successful_block")
            ->groupBy('registries.platform_id')->get();
        $stalled = SourceCircuit::query()->whereNotNull('held_at')->where('held_at', '<', $threshold)->exists();
        foreach ($rows as $row) {
            $this->line(json_encode($row, JSON_THROW_ON_ERROR));
            if (is_string($row->oldest_paused) && Carbon::parse($row->oldest_paused)->lt($threshold)) {
                $stalled = true;
            }
        }

        $procurements = (new Procurement)->getTable();
        $due = DB::table("{$procurements} as procurements")->join("{$registries} as registries", 'registries.id', '=', 'procurements.registry_id')
            ->whereNull('detail_fetched_at')->where('detail_failed', false)
            ->where(fn (Builder $query) => $query->whereNull('detail_next_retry_at')->orWhere('detail_next_retry_at', '<=', now()))
            ->select('registries.platform_id')->selectRaw('count(*) AS never_fetched_due, min(procurements.created_at) AS oldest_due_created_at')
            ->groupBy('registries.platform_id')->get();
        foreach ($due as $row) {
            $this->line(json_encode(['detail' => $row], JSON_THROW_ON_ERROR));
        }
        $failures = DB::table((new SourceIncident)->getTable())
            ->where('created_at', '>=', now()->subDay())->whereNotNull('failure_kind')
            ->select('platform_id', 'failure_kind')->selectRaw("COALESCE(context->>'stage', 'unknown') AS stage, count(*) AS incidents_24h")
            ->groupBy('platform_id', 'failure_kind')->groupByRaw("COALESCE(context->>'stage', 'unknown')")->get();
        foreach ($failures as $row) {
            $this->line(json_encode(['source' => $row], JSON_THROW_ON_ERROR));
        }

        return $stalled ? self::FAILURE : self::SUCCESS;
    }
}
