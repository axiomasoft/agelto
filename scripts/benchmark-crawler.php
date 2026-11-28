<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Fetch;
use App\Models\Procurement\Crawler\Run;
use App\Models\Procurement\Crawler\SourceIncident;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\ProcurementEvent;
use App\Models\Procurement\ProcurementRevision;
use App\Services\Procurement\BoundedResponseReader;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\TestEnvironmentGuard;
use Tests\Support\TestFilesystemIsolation;

// Run only inside the disposable audit Docker network, never against a user's stack.
if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== 'agelto_test') {
    throw new RuntimeException('Requires explicit APP_ENV=testing and DB_DATABASE=agelto_test.');
}
require dirname(__DIR__).'/tests/bootstrap.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestFilesystemIsolation::configure($app);
TestEnvironmentGuard::assertBootstrapTestDatabase($app);
TestEnvironmentGuard::assertIsolatedTestFilesystem($app);

$socket = stream_socket_server('tcp://127.0.0.1:0');
$address = stream_socket_get_name($socket, false);
$port = (int) substr(strrchr($address, ':'), 1);
fclose($socket);
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:'.$port, base_path('tests/Support/Http/crawler-fault-server.php')],
    [0 => ['pipe', 'r'], 1 => ['file', '/tmp/agelto-benchmark-http.log', 'a'], 2 => ['file', '/tmp/agelto-benchmark-http.log', 'a']], $pipes);
$fixtures = [];
try {
    $ready = false;
    for ($i = 0; $i < 100; $i++) {
        $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.01);
        if (is_resource($probe)) {
            fclose($probe);
            $ready = true;
            break;
        }
        usleep(10_000);
    }
    if (! $ready) {
        throw new RuntimeException('Local fault server did not start.');
    }
    $results = [];
    foreach (in_array('--faults-only', $argv, true) ? [] : [1, 2, 4] as $concurrency) {
        $workers = [];
        for ($worker = 1; $worker <= $concurrency; $worker++) {
            $platform = Platform::factory()->create(['code' => 'bench-'.$concurrency.'-'.$worker]);
            $registry = Registry::factory()->create(['platform_id' => $platform->id, 'code' => 'fz44']);
            config(['crawler.platforms.'.$platform->getAttribute('code') => config('crawler.platforms.eis')]);
            $crawler = Crawler::factory()->create(['registry_id' => $registry->id]);
            $run = Run::factory()->for($crawler)->create(['cursor' => null]);
            $fixtures[] = [$platform->id, $registry->id, $crawler->id, $run->id];
            for ($queued = 0; $queued < 4; $queued++) {
                Queue::connection('redis')->pushRaw(json_encode([
                    'uuid' => (string) Str::uuid(), 'displayName' => 'AuditBenchmark',
                    'job' => 'AuditBenchmark', 'attempts' => 0, 'queued_at' => microtime(true), 'data' => [],
                ], JSON_THROW_ON_ERROR), 'audit-benchmark-'.$run->id);
            }
            $workers[(string) $worker] = <<<PHP
config(['crawler.platforms.bench-{$concurrency}-{$worker}' => config('crawler.platforms.eis')]);
\$crawler = \App\Models\Procurement\Crawler\Crawler::findOrFail({$crawler->id});
\$run = \App\Models\Procurement\Crawler\Run::findOrFail({$run->id});
\$manager = app(\App\Services\Procurement\CrawlerRunManager::class);
\$manager->acquireExecutionLease(\$run);
\$latencies = [];
\$queueAges = [];
\$lockMs = [];
\Illuminate\Support\Facades\DB::listen(function (\Illuminate\Database\Events\QueryExecuted \$query) use (&\$lockMs): void {
    if (str_contains(\$query->sql, 'for update') || str_contains(\$query->sql, 'pg_advisory_xact_lock')) {
        \$lockMs[] = \$query->time;
    }
});
\$storage = \Illuminate\Support\Facades\Storage::disk('s3');
\$delayed = new class(\$storage->getDriver(), \$storage->getAdapter(), \$storage->getConfig()) extends \Illuminate\Filesystem\FilesystemAdapter {
    public function put(\$path, \$contents, \$options = []) {
        usleep(50_000);
        return parent::put(\$path, \$contents, \$options);
    }
};
\Illuminate\Support\Facades\Storage::set('s3', \$delayed);
\$started = hrtime(true);
\$statuses = [];
for (\$round = 0; \$round < 4; \$round++) {
    \$job = \Illuminate\Support\Facades\Queue::connection('redis')->pop('audit-benchmark-{$run->id}');
    if (\$job === null) throw new \RuntimeException('Benchmark queue lost a job.');
    \$queueAges[] = (microtime(true) - \$job->payload()['queued_at']) * 1000;
    \$blockStart = hrtime(true);
    for (\$attempt = 1; \$attempt <= 3; \$attempt++) {
        \$response = \Illuminate\Support\Facades\Http::withOptions(['stream' => true, 'read_timeout' => 1])->timeout(5)->get('http://127.0.0.1:{$port}/', ['worker' => {$worker}, 'attempt' => \$attempt]);
        \$statuses[] = \$response->status();
        \$body = app(\App\Services\Procurement\BoundedResponseReader::class)->read(\$response, 8_388_608, microtime(true) + 5);
        if (\$response->status() === 200) break;
    }
    \$stream = fopen('php://temp', 'w+');
    fwrite(\$stream, mb_convert_encoding(\$body, 'UTF-8', 'Windows-1251'));
    rewind(\$stream);
    \$header = fgetcsv(\$stream, null, ';', '"', '');
    \$rows = [];
    while ((\$fields = fgetcsv(\$stream, null, ';', '"', '')) !== false) {
        \$rows[] = array_combine(\$header, \$fields);
    }
    fclose(\$stream);
    \$batch = new \App\Dto\Procurement\Crawler\ListingBatch(\$rows, \$body, hash('sha256', \$body), count(\$rows), 200, 1, ['round' => \$round], header: \$header, observedAt: now());
    \$ingestor = app(\App\Services\Procurement\CsvBlockIngestor::class);
    \$artifact = \$ingestor->stageArtifact(\$crawler, \$run, \$batch);
    \$result = \$manager->withinExecutionLease(\$run, fn () => \$ingestor->ingest(\$crawler, \$run, \$batch, \$artifact));
    if (\$result->failure !== null) throw new \RuntimeException('Benchmark block failed.');
    \$latencies[] = (hrtime(true) - \$blockStart) / 1e6;
    \$job->delete();
}
\$manager->releaseExecutionLease(\$run);
return ['blocks_ms' => \$latencies, 'queue_age_ms' => \$queueAges, 'elapsed_ms' => (hrtime(true) - \$started) / 1e6, 'peak_bytes' => memory_get_peak_usage(true), 'max_lock_query_ms' => max(\$lockMs ?: [0]), 'statuses' => array_count_values(\$statuses), 'procurements' => \App\Models\Procurement\Procurement::where('registry_id', {$registry->id})->count()];
PHP;
        }
        $start = hrtime(true);
        $round = ConcurrentTransactions::concurrently($workers);
        $elapsed = (hrtime(true) - $start) / 1e9;
        $durations = array_merge(...array_column($round, 'blocks_ms'));
        sort($durations);
        $queueAges = array_merge(...array_column($round, 'queue_age_ms'));
        sort($queueAges);
        $results[] = ['workers' => $concurrency, 'elapsed_seconds' => $elapsed, 'rows_per_second' => $concurrency * 2000 / $elapsed,
            'p95_queue_age_ms' => $queueAges[(int) ceil(count($queueAges) * 0.95) - 1],
            'p95_block_ms' => $durations[(int) ceil(count($durations) * 0.95) - 1],
            'peak_worker_bytes' => max(array_column($round, 'peak_bytes')), 'max_lock_query_ms' => max(array_column($round, 'max_lock_query_ms')), 'worker_results' => $round];
    }
    $crashes = [];
    foreach (['after_http', 'during_commit', 'after_commit'] as $point) {
        $platform = Platform::factory()->create(['code' => 'kill-'.$point]);
        $registry = Registry::factory()->create(['platform_id' => $platform->id, 'code' => 'fz44']);
        config(['crawler.platforms.kill-'.$point => config('crawler.platforms.eis')]);
        $crawler = Crawler::factory()->create(['registry_id' => $registry->id]);
        $run = Run::factory()->for($crawler)->create(['cursor' => ['blockFrom' => 1]]);
        $fixtures[] = [$platform->id, $registry->id, $crawler->id, $run->id];
        $code = <<<PHP
config(['crawler.platforms.kill-{$point}' => config('crawler.platforms.eis')]);
\$crawler = \App\Models\Procurement\Crawler\Crawler::findOrFail({$crawler->id});
\$run = \App\Models\Procurement\Crawler\Run::findOrFail({$run->id});
\$manager = app(\App\Services\Procurement\CrawlerRunManager::class);
\$manager->acquireExecutionLease(\$run);
\$response = \Illuminate\Support\Facades\Http::timeout(2)->get('http://127.0.0.1:{$port}/?attempt=3');
if (! \$response->successful()) throw new \RuntimeException('Fault HTTP prerequisite failed.');
if ('{$point}' === 'after_http') posix_kill(getmypid(), SIGKILL);
\$batch = \Tests\Support\Crawler\SourceControlScenario::rows(99);
\$ingestor = app(\App\Services\Procurement\CsvBlockIngestor::class);
\$artifact = \$ingestor->stageArtifact(\$crawler, \$run, \$batch);
\$manager->withinExecutionLease(\$run, function () use (\$manager, \$ingestor, \$crawler, \$run, \$batch, \$artifact): void {
    \$ingestor->ingest(\$crawler, \$run, \$batch, \$artifact);
    \$manager->updateCursor(\$run, new \App\Dto\Procurement\Crawler\ListingCursor(blockFrom: 501));
    if ('{$point}' === 'during_commit') posix_kill(getmypid(), SIGKILL);
});
posix_kill(getmypid(), SIGKILL);
return ['unexpected' => true];
PHP;
        $killed = false;
        try {
            ConcurrentTransactions::execute($code);
        } catch (RuntimeException $exception) {
            $killed = true;
        }
        $run->refresh();
        $count = Procurement::query()->where('registry_id', $registry->id)->count();
        $expected = $point === 'after_commit' ? 2 : 0;
        $offset = $run->cursor?->get('blockFrom');
        if (! $killed || $count !== $expected || $offset !== ($expected === 2 ? 501 : 1)) {
            throw new RuntimeException('Crash consistency failed at '.$point);
        }
        DB::table($run->getTable())->where('id', $run->id)->update(['execution_lease_expires_at' => now()->subSecond()]);
        $replay = ConcurrentTransactions::execute(<<<PHP
config(['crawler.platforms.kill-{$point}' => config('crawler.platforms.eis')]);
\$crawler = \App\Models\Procurement\Crawler\Crawler::findOrFail({$crawler->id});
\$run = \App\Models\Procurement\Crawler\Run::findOrFail({$run->id});
\$manager = app(\App\Services\Procurement\CrawlerRunManager::class);
if (! \$manager->acquireExecutionLease(\$run)) throw new \RuntimeException('Cannot recover crash lease.');
\$batch = \Tests\Support\Crawler\SourceControlScenario::rows(99);
\$ingestor = app(\App\Services\Procurement\CsvBlockIngestor::class);
\$artifact = \$ingestor->stageArtifact(\$crawler, \$run, \$batch);
\$manager->withinExecutionLease(\$run, fn () => \$ingestor->ingest(\$crawler, \$run, \$batch, \$artifact));
\$manager->releaseExecutionLease(\$run);
return ['count' => \App\Models\Procurement\Procurement::where('registry_id', {$registry->id})->count(), 'max_revision' => \App\Models\Procurement\Procurement::where('registry_id', {$registry->id})->max('revision_count')];
PHP);
        if ($replay['count'] !== 2 || $replay['max_revision'] !== 1) {
            throw new RuntimeException('Crash replay is not idempotent.');
        }
        $crashes[$point] = ['rows_before_recovery' => $count, 'offset_before_recovery' => $offset, 'replay' => $replay];
    }
    $prototype = Run::factory()->for($crawler)->make(['status' => 'paused', 'stats' => ['source_suspended' => true]])->getAttributes();
    for ($chunk = 0; $chunk < 10; $chunk++) {
        DB::table($run->getTable())->insert(array_fill(0, 1000, $prototype));
    }
    DB::statement('ANALYZE '.$run->getTable());
    $explain = DB::selectOne("EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) SELECT id FROM prc_crawler_runs WHERE status = 'paused' AND purpose = 'profile' AND (stats->>'source_suspended') = 'true' AND (source_resume_claimed_until IS NULL OR source_resume_claimed_until <= now()) ORDER BY source_resume_checked_at ASC NULLS FIRST, id LIMIT 10");
    $explain = json_decode($explain->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR);
    $faults = [];
    foreach (['gzip', 'slow'] as $mode) {
        $start = hrtime(true);
        $rejected = false;
        try {
            $response = Http::withOptions(['stream' => true, 'read_timeout' => 0.2])->timeout(1)->get('http://127.0.0.1:'.$port.'/?mode='.$mode);
            app(BoundedResponseReader::class)->read($response, 1_048_576, microtime(true) + 0.5);

        } catch (Throwable $exception) {
            $rejected = $mode === 'slow' || str_contains($exception->getMessage(), 'decoded bytes');
            $faults[$mode] = ['elapsed_ms' => (hrtime(true) - $start) / 1e6, 'exception' => $exception::class, 'message' => $exception->getMessage()];
        }
        if (! $rejected) {
            throw new RuntimeException('Fault was not rejected as expected: '.$mode);
        }
    }
    echo json_encode(['runs' => $results, 'faults' => $faults, 'crashes' => $crashes, 'resume_explain_10000' => $explain], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    proc_terminate($server);
    proc_close($server);
    foreach ($fixtures as [$platformId, $registryId, $crawlerId, $runId]) {
        // The entire disposable DB is destroyed after this benchmark. Delete only our root
        // fixtures here; FK cascades own dependent rows, never a global truncate.
        $ids = Procurement::query()->where('registry_id', $registryId)->pluck('id');
        ProcurementEvent::query()->whereIn('procurement_id', $ids)->delete();
        ProcurementRevision::query()->whereIn('procurement_id', $ids)->delete();
        Procurement::query()->whereIn('id', $ids)->delete();
        Queue::connection('redis')->clear('audit-benchmark-'.$runId);
        $runIds = Run::query()->where('crawler_id', $crawlerId)->pluck('id');
        Fetch::query()->whereIn('crawler_run_id', $runIds)->delete();
        SourceIncident::query()->where('crawler_id', $crawlerId)->delete();
        Run::query()->whereIn('id', $runIds)->delete();
        Crawler::query()->whereKey($crawlerId)->delete();
        DB::table((new Platform)->getTable())->where('id', $platformId)->delete();
    }
}
