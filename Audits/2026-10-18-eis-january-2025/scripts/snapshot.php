<?php

declare(strict_types=1);

// Read-only operational snapshot. Never serialize models, credentials, URLs or error text.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

$result = ['observed_at_utc' => gmdate(DATE_ATOM), 'read_only' => true];

function capture(callable $read): mixed
{
    try {
        return $read();
    } catch (Throwable $exception) {
        return ['unavailable' => true, 'exception_class' => $exception::class];
    }
}

function decodeObject(mixed $value): array
{
    return is_string($value) ? (json_decode($value, true, flags: JSON_THROW_ON_ERROR) ?? []) : (array) $value;
}

// Stats may grow new fields: preserve numbers/booleans, hash all unrecognized strings.
function safeStats(mixed $value): mixed
{
    if (is_array($value)) {
        $safe = [];
        foreach ($value as $key => $child) {
            if (is_string($key) && preg_match('/password|secret|token|url|host|username/i', $key)) {
                continue;
            }
            $safe[$key] = safeStats($child);
        }

        return $safe;
    }

    return is_string($value) ? ['sha256' => hash('sha256', $value)] : $value;
}

function tableCount(string $table): array
{
    if (! Schema::hasTable($table)) {
        return ['exists' => false];
    }

    return ['exists' => true, 'count' => DB::table($table)->count(),
        'max_id' => Schema::hasColumn($table, 'id') ? DB::table($table)->max('id') : null];
}

try {
    $root = dirname(__DIR__, 3);
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $options = getopt('', ['run:']);
    $runId = $options['run'] ?? null;
    if ($runId !== null && (! is_string($runId) || ! ctype_digit($runId) || (int) $runId < 1)) {
        throw new InvalidArgumentException('Invalid run ID.');
    }
    $runId = $runId === null ? null : (int) $runId;
    $result['run_filter'] = $runId;
    $result['environment'] = app()->environment();
    $result['config'] = [
        'downstream_enabled' => config('crawler.downstream_enabled', true),
        'ai_screening_enabled' => config('ai.screening.enabled'),
        'ai_extraction_enabled' => config('ai.extraction.enabled'),
        'delta_scan_enabled' => config('crawler.tracking.delta_scan_enabled'),
        'queue_default' => config('queue.default'),
        'checkpoint_seconds' => config('crawler.run.checkpoint_seconds'),
    ];

    $connection = DB::connection();
    if ($connection->getDriverName() === 'pgsql') {
        // Every subsequent database statement is protected from accidental mutation.
        DB::statement('SET default_transaction_read_only = on');
    }
    $result['database'] = capture(fn () => [
        'driver' => $connection->getDriverName(),
        'name' => $connection->getDatabaseName(),
        'read_only_enforced' => $connection->getDriverName() === 'pgsql',
    ]);

    $result['profiles'] = capture(function (): array {
        if (! Schema::hasTable('prc_crawlers')) {
            return ['exists' => false];
        }
        return DB::table('prc_crawlers')->orderBy('id')->get([
            'id', 'registry_id', 'name', 'mode', 'execution_lane', 'source_method',
            'is_active', 'schedule', 'proxy_mode', 'proxy_country', 'filter',
        ])->map(function ($row): array {
            $profile = (array) $row;
            $profile['filter'] = array_intersect_key(decodeObject($row->filter), array_flip([
                'publishedFrom', 'publishedTo', 'okpd2Codes', 'priceFrom', 'priceTo',
                'stageApplications', 'stageCommission', 'stageCompleted', 'stageCancelled',
                'sortField', 'sortAscending',
            ]));
            return $profile;
        })->all();
    });

    $result['runs'] = capture(function () use ($runId): array {
        if (! Schema::hasTable('prc_crawler_runs')) {
            return ['exists' => false];
        }
        $query = DB::table('prc_crawler_runs')->orderByDesc('id')->limit(20);
        if ($runId !== null) {
            $query->where('id', $runId);
        }
        return $query->get([
            'id', 'crawler_id', 'status', 'purpose', 'cursor', 'stats', 'started_at',
            'finished_at', 'last_heartbeat_at', 'execution_lease_expires_at', 'attempts',
            'last_error', 'last_error_at', 'next_retry_at', 'failed_permanently',
            'stop_requested_at', 'stop_kind',
        ])->map(function ($row): array {
            $run = (array) $row;
            $run['has_error'] = $row->last_error !== null;
            $run['error_sha256'] = $row->last_error === null ? null : hash('sha256', $row->last_error);
            unset($run['last_error']);
            $cursor = decodeObject($row->cursor);
            $run['cursor'] = array_intersect_key($cursor, array_flip([
                'blockFrom', 'day', 'priceStack', 'dateField', 'lastDedupSignature', 'blocksInInterval',
                'block_from', 'price_stack', 'date_field', 'last_dedup_signature', 'blocks_in_interval',
            ]));
            $run['stats'] = safeStats(decodeObject($row->stats));
            return $run;
        })->all();
    });

    $result['fetches'] = capture(function () use ($runId): array {
        if (! Schema::hasTable('prc_fetches')) {
            return ['exists' => false];
        }
        $query = DB::table('prc_fetches');
        if ($runId !== null) {
            $query->where('crawler_run_id', $runId);
        }
        return [
            'count' => (clone $query)->count(),
            'max_id' => (clone $query)->max('id'),
            'last_fetched_at' => (clone $query)->max('fetched_at'),
            'without_proxy' => (clone $query)->whereNull('proxy_id')->count(),
            'by_run_stage_status' => (clone $query)
                ->select('crawler_run_id', 'stage', 'http_status', 'proxy_source')
                ->selectRaw('COUNT(*) AS fetches, SUM(rows_total) AS rows_total, SUM(rows_new) AS rows_new, SUM(rows_updated) AS rows_updated, SUM(rows_errors) AS rows_errors, MAX(duration_ms) AS max_duration_ms, SUM(wait_ms) AS total_wait_ms')
                ->groupBy('crawler_run_id', 'stage', 'http_status', 'proxy_source')
                ->orderBy('crawler_run_id')->orderBy('stage')->orderBy('http_status')->get()->all(),
        ];
    });

    $result['procurements'] = capture(function (): array {
        if (! Schema::hasTable('prc_procurements')) {
            return ['exists' => false];
        }
        $query = DB::table('prc_procurements');
        return tableCount('prc_procurements') + [
            'published_at_min' => (clone $query)->min('published_at'),
            'published_at_max' => (clone $query)->max('published_at'),
            'details_fetched' => (clone $query)->whereNotNull('detail_fetched_at')->count(),
            'detail_attempts_sum' => (clone $query)->sum('detail_attempts'),
            'revisions' => tableCount('prc_revisions'),
        ];
    });

    foreach (['prc_eis_details', 'prc_documents', 'prc_document_versions', 'prc_document_representations',
        'prc_ai_analyses', 'prc_ai_analysis_runs', 'prc_estimates', 'failed_jobs'] as $table) {
        $result['downstream'][$table] = capture(fn () => tableCount($table));
    }

    $result['proxy_health'] = capture(fn () => DB::table('prc_proxies')->orderBy('id')->get([
        'id', 'country', 'scopes', 'is_active', 'fails_count', 'open_count', 'open_until',
        'last_failure_reason', 'last_failure_at', 'last_success_at',
    ])->all());

    foreach (['redis' => ['default', 'crawl', 'crawl_detail', 'doc_download', 'ai_screening', 'ai_extraction', 'ai_shadow'],
        'redis_prepare' => ['doc_prepare'], 'redis_crawl_long' => ['crawl_long']] as $queueConnection => $queues) {
        foreach ($queues as $queue) {
            $result['queues'][$queueConnection][$queue] = capture(function () use ($queueConnection, $queue): array {
                $redis = Redis::connection(config("queue.connections.{$queueConnection}.connection", 'default'));
                $key = 'queues:'.$queue;
                return ['ready' => $redis->llen($key), 'delayed' => $redis->zcard($key.':delayed'),
                    'reserved' => $redis->zcard($key.':reserved')];
            });
        }
    }
} catch (Throwable $exception) {
    $result['fatal'] = ['exception_class' => $exception::class];
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
exit(isset($result['fatal']) ? 1 : 0);
