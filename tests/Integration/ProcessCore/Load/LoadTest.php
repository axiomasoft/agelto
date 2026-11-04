<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\Support\ProcessCore\AcceptanceRuntime;
use Tests\Support\ProcessCore\AcceptanceScenario;
use Tests\Support\ProcessCore\ExecutionFixtures;

pest()->group('admission-fairness', 'sustained-workload', 'bounded-network-demand', 'load');

test('совместная нагрузка сохраняет W C immutable age и FIFO двух платформ при fresh ingress и repeated faults', function (): void {
    // A single-file/regression run proves the same assertions on a small corpus. Only the load
    // target requests the mandatory 1800-second/1000-document profile and validates its report.
    $width = (int) (getenv('PROCESS_CORE_LOAD_WIDTH') ?: 10);
    $duration = (int) (getenv('PROCESS_CORE_LOAD_SECONDS') ?: 0);
    $total = $duration > 0 ? 1000 : 12;
    $scenario = new AcceptanceScenario($width);
    $start = hrtime(true);
    $faults = $samples = $ids = $feeders = [];
    try {
        $ids = $scenario->documents(min(100, intdiv($total, 2)), old: true);
        $originalAge = DB::table('prc_process_work')->whereIn('id', $ids)->orderBy('id')->pluck('created_at', 'id')->all();
        $scenario->start();
        $start = hrtime(true); // Setup is excluded from the sustained soak duration.
        $nextIngress = $nextSample = 0.0;
        $nextFault = 15.0;
        do {
            $elapsed = (hrtime(true) - $start) / 1e9;
            if (count($ids) < $total && $elapsed >= $nextIngress) {
                $ids = [...$ids, ...$scenario->documents(min(10, $total - count($ids)))];
                $race = $scenario->twoFeeders();
                expect($race['a']['pid'])->not->toBe($race['b']['pid']);
                $feeders[] = $race;
                $nextIngress = $elapsed + ($duration > 0 ? $duration / 90 : 0);
            }
            if ($duration > 0 && $elapsed >= $nextFault && count($ids) < $total) {
                // Give each fault its own real input even when the preceding wave drained.
                $ids = [...$ids, ...$scenario->documents(min(2, $total - count($ids)))];
                @unlink($scenario->root.'/boundary.json');
                @unlink($scenario->root.'/arm-after-lease.claimed');
                touch($scenario->root.'/arm-after-lease');
                $marker = $scenario->awaitBoundary('after-lease');
                expect(posix_kill($marker['pid'], SIGKILL))->toBeTrue();
                Redis::connection()->flushdb();
                $scenario->stop();
                $scenario->start();
                $faults[] = ['boundary' => 'after-lease', 'signal' => 'SIGKILL', 'work' => $marker['work'],
                    'at_seconds' => $elapsed, 'redis_flush' => true, 'horizon_restart' => true];
                $nextFault = $elapsed + 300;
            }
            $scenario->recover();
            $scenario->tick();
            $scenario->assertBounds();
            if (is_file($scenario->root.'/faults.jsonl')) {
                throw new RuntimeException((string) file_get_contents($scenario->root.'/faults.jsonl'));
            }
            if ($elapsed >= $nextSample) {
                $counts = ExecutionFixtures::laneCounts('analysis');
                $lag = DB::selectOne('SELECT coalesce(max(extract(epoch FROM (clock_timestamp()-available_at))),0) AS age,
                    count(*) AS pending FROM prc_process_outbox WHERE published_at IS NULL');
                $samples[] = ['at_seconds' => $elapsed, ...$counts, 'pending_outbox' => (int) $lag->pending,
                    'outbox_age_seconds' => (float) $lag->age, 'controller_rss_kib' => AcceptanceRuntime::rss(),
                    'transitions' => DB::table('prc_process_transitions')->count(),
                    'network_queued' => DB::table('prc_network_demands')->where('state', 'queued')->count(),
                    'completed' => DB::table('prc_process_work')->where('state', 'succeeded')->count(),
                    'oldest_eligible_seconds' => (float) DB::selectOne("SELECT coalesce(max(extract(epoch FROM (clock_timestamp()-created_at))),0) AS age
                        FROM prc_process_work WHERE state='ready' OR (state='deferred' AND due_at<=clock_timestamp())")->age];
                $nextSample = $elapsed + 1;
            }
            usleep(100_000);
        } while ($elapsed < $duration || count($ids) < $total);
        $scenario->drain(180);
        $scenario->stop();
        $attempts = DB::select('SELECT percentile_cont(0.5) WITHIN GROUP (ORDER BY extract(epoch FROM (finished_at-started_at))) AS p50,
            percentile_cont(0.95) WITHIN GROUP (ORDER BY extract(epoch FROM (finished_at-started_at))) AS p95,
            percentile_cont(0.99) WITHIN GROUP (ORDER BY extract(epoch FROM (finished_at-started_at))) AS p99
            FROM prc_process_attempts WHERE finished_at IS NOT NULL');
        $inversions = (int) DB::selectOne('SELECT count(*) AS n FROM (SELECT d.ticket_seq,
            lag(d.ticket_seq) OVER (ORDER BY p.created_at,p.id) AS previous
            FROM prc_network_permits p JOIN prc_network_demands d ON d.id=p.demand_id) ordered WHERE ticket_seq<previous')->n;
        $executions = array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            file($scenario->root.'/executions.jsonl', FILE_IGNORE_NEW_LINES));
        $report = [
            'format' => 'process-core-load/v1', 'width' => $width, 'running_cap' => 10,
            'duration_seconds' => (hrtime(true) - $start) / 1e9, 'documents' => count($ids),
            'platforms' => DB::table('prc_process_work')->distinct()->count('platform_id'),
            'slices_per_work' => 3, 'samples' => $samples, 'faults' => $faults, 'feeder_races' => $feeders,
            'attempt_seconds' => $attempts, 'fifo_grant_inversions' => $inversions,
            'cohort_wait_seconds' => DB::select("SELECT w.cohort_key,count(*) AS works,
                percentile_cont(0.5) WITHIN GROUP (ORDER BY extract(epoch FROM (first_admission.at-w.created_at))) AS p50,
                percentile_cont(0.95) WITHIN GROUP (ORDER BY extract(epoch FROM (first_admission.at-w.created_at))) AS p95,
                percentile_cont(0.99) WITHIN GROUP (ORDER BY extract(epoch FROM (first_admission.at-w.created_at))) AS p99
                FROM prc_process_work w CROSS JOIN LATERAL
                (SELECT min(occurred_at) AS at FROM prc_process_transitions WHERE work_id=w.id AND new_state='admitted') first_admission
                GROUP BY w.cohort_key"),
            'worker_observations' => $executions, 'worker_rss_cap_kib' => 262144,
            'controller_rss_cap_kib' => 262144, 'rss_noise_kib' => 8192,
            'accepted' => DB::table('prc_process_transitions')->where('new_state', 'succeeded')->count(),
            'active_reservations_after_drain' => DB::table('prc_process_reservations')->count(),
            'pending_outbox_after_drain' => DB::table('prc_process_outbox')->whereNull('published_at')->count(),
            'unaccounted_work' => DB::table('prc_process_work')->where('state', '!=', 'succeeded')->count(),
            'requests' => DB::table('prc_request_attempts')->count(), 'server_receipts' => count($scenario->wire->receipts()),
            'history_rows' => DB::table('prc_process_transitions')->count(),
            'transitions_by_state' => DB::table('prc_process_transitions')->selectRaw('entity_kind,new_state,count(*) AS rows')
                ->groupBy('entity_kind', 'new_state')->get()->all(),
            'outbox_history' => DB::table('prc_process_outbox')->selectRaw('kind,count(*) AS rows,count(published_at) AS published')
                ->groupBy('kind')->get()->all(),
            'throughput_documents_per_second' => $total / ((hrtime(true) - $start) / 1e9),
            'resources' => ['php' => PHP_VERSION, 'kernel' => php_uname('r'),
                'cpu_logical' => substr_count((string) file_get_contents('/proc/cpuinfo'), 'processor'),
                'memory_cgroup' => is_file('/sys/fs/cgroup/memory.max') ? trim((string) file_get_contents('/sys/fs/cgroup/memory.max')) : 'unavailable',
                'database_bytes' => (int) DB::selectOne('SELECT pg_database_size(current_database()) AS bytes')->bytes,
                'accounted_storage_bytes' => DB::table('prc_storage_domains')->sum('accounted_durable_bytes')],
            'claims' => 'real Horizon and Process/network authorities; synthetic screening content, no model accuracy or production throughput claim',
        ];
        $destination = getenv('PROCESS_CORE_EVIDENCE_PATH') ?: $scenario->root.'/load.json';
        file_put_contents($destination, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        expect($report['unaccounted_work'])->toBe(0)->and($report['accepted'])->toBe($total)
            ->and($report['active_reservations_after_drain'])->toBe(0)->and($report['pending_outbox_after_drain'])->toBe(0)
            ->and($report['fifo_grant_inversions'])->toBe(0)
            ->and(DB::table('prc_process_work')->whereIn('id', array_keys($originalAge))->orderBy('id')->pluck('created_at', 'id')->all())->toBe($originalAge)
            ->and($report['requests'])->toBeGreaterThanOrEqual(3 * $total)
            ->and($report['server_receipts'])->toBeGreaterThanOrEqual(3 * $total);
    } finally {
        $scenario->close();
    }
});
