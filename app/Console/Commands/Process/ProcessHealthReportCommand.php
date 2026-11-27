<?php

declare(strict_types=1);

namespace App\Console\Commands\Process;

use App\Enums\Procurement\Process\Core\StageCode;
use App\Repositories\Procurement\Process\Support\LedgerRow;
use App\Services\Procurement\Process\Infrastructure\ProcessPolicy;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Read-only scheduled report; thresholds do not trigger external notifications. */
#[Signature('process:health-report {--sli : Показать операционные SLI} {--json : Вывести отчёт в формате JSON}')]
final class ProcessHealthReportCommand extends Command
{
    public function handle(ProcessPolicy $policy): int
    {
        $rows = LedgerRow::all("SELECT stage,state,reason_code,count(*) AS total
            FROM prc_process_work WHERE state NOT IN ('succeeded','failed_item','cancelled','superseded')
            GROUP BY stage,state,reason_code ORDER BY stage,state,reason_code");
        if (! $this->option('sli') && ! $this->option('json')) {
            foreach ($rows as $row) {
                $this->line($row->string('stage').' '.$row->string('state').' '.($row->nullableString('reason_code') ?? 'eligible').' '.$row->int('total'));
            }

            return self::SUCCESS;
        }

        $work = array_map(static fn (LedgerRow $row): array => [
            'stage' => $row->string('stage'),
            'state' => $row->string('state'),
            'reason_code' => $row->nullableString('reason_code'),
            'total' => $row->int('total'),
        ], $rows);
        $sli = $this->sli($policy);

        if ($this->option('json')) {
            $this->line(json_encode(['work' => $work, 'sli' => $sli], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $this->line($row->string('stage').' '.$row->string('state').' '.($row->nullableString('reason_code') ?? 'eligible').' '.$row->int('total'));
        }
        foreach ($sli['oldest_waiting_by_lane'] as $lane) {
            $this->line('sli.oldest_waiting_by_lane lane_id='.$lane['lane_id'].' lane_key='.$lane['lane_key'].' waiting_count='.$lane['waiting_count'].' oldest_waiting_seconds='.$lane['oldest_waiting_seconds']);
        }
        $this->line('sli.stale_lease running_total='.$sli['stale_lease']['running_total'].' stale_running='.$sli['stale_lease']['stale_running'].' stale_lease_rate='.$sli['stale_lease']['stale_lease_rate']);
        foreach ($sli['pg_dead_tuples'] as $table) {
            $this->line('sli.pg_dead_tuples relname='.$table['relname'].' n_dead_tup='.$table['n_dead_tup'].' last_autovacuum='.($table['last_autovacuum'] ?? 'null').' last_vacuum='.($table['last_vacuum'] ?? 'null'));
        }
        $this->line('sli.estimate_validation total_estimates='.$sli['estimate_validation']['total_estimates'].' valid_estimates='.$sli['estimate_validation']['valid_estimates'].' valid_rate='.($sli['estimate_validation']['valid_rate'] ?? 'null'));

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     oldest_waiting_by_lane: list<array{lane_id: string, lane_key: string, oldest_waiting_seconds: float, waiting_count: int}>,
     *     stale_lease: array{running_total: int, stale_running: int, stale_lease_rate: float},
     *     pg_dead_tuples: list<array{relname: string, n_dead_tup: int, last_autovacuum: ?string, last_vacuum: ?string}>,
     *     estimate_validation: array{total_estimates: int, valid_estimates: int, valid_rate: ?float}
     * }
     */
    private function sli(ProcessPolicy $policy): array
    {
        $stageLanes = [];
        $bindings = [];
        foreach (StageCode::cases() as $stage) {
            foreach ($policy->requiredLanes($stage) as $laneKey) {
                $stageLanes[] = '(?, ?)';
                $bindings[] = $stage->value;
                $bindings[] = $laneKey;
            }
        }

        $waiting = LedgerRow::all('WITH stage_lanes(stage, lane_key) AS (VALUES '.implode(', ', $stageLanes).')
            SELECT l.id AS lane_id, l.lane_key,
                   COALESCE(EXTRACT(EPOCH FROM (clock_timestamp() - MIN(w.created_at))), 0) AS oldest_waiting_seconds,
                   COUNT(*) AS waiting_count
            FROM prc_process_work w
            JOIN stage_lanes m ON m.stage = w.stage
            JOIN prc_process_lanes l ON l.lane_key = m.lane_key
            WHERE w.state IN (\'ready\', \'deferred\')
            GROUP BY l.id, l.lane_key ORDER BY l.lane_key', $bindings);
        $lease = LedgerRow::one("SELECT COUNT(*) FILTER (WHERE state = 'running') AS running_total,
                   COUNT(*) FILTER (WHERE state = 'running' AND lease_until < clock_timestamp()) AS stale_running
            FROM prc_process_work");
        $tables = LedgerRow::all("SELECT relname, COALESCE(n_dead_tup, 0) AS n_dead_tup, last_autovacuum, last_vacuum
            FROM pg_stat_user_tables WHERE relname IN ('prc_process_work', 'prc_process_outbox') ORDER BY relname");
        $estimates = LedgerRow::one("SELECT COUNT(*) AS total_estimates,
                   COUNT(*) FILTER (WHERE validation_status = 'valid') AS valid_estimates FROM prc_estimates");

        $runningTotal = $lease?->int('running_total') ?? 0;
        $staleRunning = $lease?->int('stale_running') ?? 0;
        $totalEstimates = $estimates?->int('total_estimates') ?? 0;
        $validEstimates = $estimates?->int('valid_estimates') ?? 0;

        return [
            'oldest_waiting_by_lane' => array_map(static fn (LedgerRow $row): array => [
                'lane_id' => $row->string('lane_id'),
                'lane_key' => $row->string('lane_key'),
                'oldest_waiting_seconds' => (float) $row->string('oldest_waiting_seconds'),
                'waiting_count' => $row->int('waiting_count'),
            ], $waiting),
            'stale_lease' => [
                'running_total' => $runningTotal,
                'stale_running' => $staleRunning,
                'stale_lease_rate' => $runningTotal === 0 ? 0.0 : $staleRunning / $runningTotal,
            ],
            'pg_dead_tuples' => array_map(static fn (LedgerRow $row): array => [
                'relname' => $row->string('relname'),
                'n_dead_tup' => $row->int('n_dead_tup'),
                'last_autovacuum' => $row->nullableString('last_autovacuum'),
                'last_vacuum' => $row->nullableString('last_vacuum'),
            ], $tables),
            'estimate_validation' => [
                'total_estimates' => $totalEstimates,
                'valid_estimates' => $validEstimates,
                'valid_rate' => $totalEstimates === 0 ? null : $validEstimates / $totalEstimates,
            ],
        ];
    }
}
