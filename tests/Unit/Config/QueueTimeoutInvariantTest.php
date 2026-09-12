<?php

declare(strict_types=1);

use App\Jobs\Procurement\DownloadDocumentJob;
use App\Jobs\Procurement\PipelineJob;
use App\Jobs\Procurement\RevalidateDocumentJob;
use Illuminate\Queue\Attributes\Timeout;

test('скачивание и ревалидация имеют Horizon consumer с достаточным timeout', function (): void {
    foreach ([new DownloadDocumentJob(documentId: 1), new RevalidateDocumentJob(documentId: 1)] as $job) {
        $consumers = collect(config('horizon.defaults'))->filter(
            static fn (array $supervisor): bool => in_array($job->queue, $supervisor['queue'], true),
        );

        expect($consumers)->not->toBeEmpty();

        foreach ($consumers as $supervisor) {
            expect(queueJobTimeout(job: $job))->toBeLessThan($supervisor['timeout']);
        }
    }
});

test('таймаут каждого horizon-супервизора строго меньше retry_after его connection (инварианты D26 и D117)', function (): void {
    foreach ((array) config('horizon.defaults') as $name => $supervisor) {
        $connection = $supervisor['connection'];
        $retryAfter = (int) config("queue.connections.{$connection}.retry_after");

        expect($supervisor['timeout'])->toBeLessThan(
            $retryAfter,
            "Супервизор \"{$name}\" ({$connection}): timeout={$supervisor['timeout']}s ≥ retry_after={$retryAfter}s — ".
            'Redis сочтёт job осиротевшим и передиспатчит его другому воркеру, пока первый ещё легитимно работает (D26).',
        );
    }
});

test('versioned pipeline operation budget leaves the next call reserve and preserves all timeout chains', function (): void {
    $budgets = config('ai.pipeline.operation_budgets');

    expect($budgets['schema_version'])->toBe('pipeline-operation-budget/v1');

    $supervisors = [
        'prepare' => 'supervisor-doc-prepare',
        'screening' => 'supervisor-ai-screening',
        'extraction' => 'supervisor-ai-extraction',
    ];
    $connections = [
        'prepare' => 'redis_prepare',
        'screening' => 'redis',
        'extraction' => 'redis',
    ];

    foreach ($supervisors as $stage => $supervisorName) {
        $budget = $budgets[$stage];
        $supervisorTimeout = (int) config("horizon.defaults.{$supervisorName}.timeout");
        $retryAfter = (int) config("queue.connections.{$connections[$stage]}.retry_after");

        expect($budget['max_next_call_seconds'] + $budget['persistence_release_reserve_seconds'])
            ->toBeLessThanOrEqual($budget['job_timeout_seconds'])
            ->and($budget['job_timeout_seconds'])->toBeLessThan($supervisorTimeout)
            ->and($supervisorTimeout)->toBeLessThan($retryAfter)
            ->and($budget['lease_seconds'])->toBeGreaterThanOrEqual($budget['job_timeout_seconds']);
    }

    $prepare = $budgets['prepare'];
    expect($prepare['operation_timeout_seconds'])->toBe([
        'text_layer_probe' => config('ai.tools.default_timeout_seconds'),
        'ocr' => config('ai.tools.ocr_timeout_seconds'),
        'tesseract_version' => config('ai.tools.default_timeout_seconds'),
        'pdf_page_count' => config('ai.tools.default_timeout_seconds'),
    ])->and($prepare['setup_allowance_seconds'] + array_sum($prepare['operation_timeout_seconds']) + $prepare['persistence_release_reserve_seconds'])
        ->toBeLessThanOrEqual($prepare['job_timeout_seconds']);

    $screening = $budgets['screening'];
    expect($screening['setup_allowance_seconds'] + array_sum($screening['operation_timeout_seconds']) + $screening['persistence_release_reserve_seconds'])
        ->toBeLessThanOrEqual($screening['job_timeout_seconds']);

    expect(config('ai.pipeline.temporary_stale_seconds'))
        ->toBeGreaterThan(max(array_column([$prepare, $screening, $budgets['extraction']], 'lease_seconds')));

    $extraction = $budgets['extraction'];
    expect(config('ai.extraction.checkpoint_seconds'))->toBe($extraction['checkpoint_seconds'])
        ->and($extraction['checkpoint_seconds'] + $extraction['max_next_call_seconds'] + $extraction['persistence_release_reserve_seconds'])
        ->toBeLessThanOrEqual($extraction['job_timeout_seconds']);
});

test('long lane сохраняет обе timeout-цепочки и documented stop_grace_period (инвариант D117)', function (): void {
    expect(config('horizon.defaults.supervisor-crawl.timeout'))->toBe(300)
        ->and(config('queue.connections.redis.retry_after'))->toBe(420)
        ->and(config('horizon.defaults.supervisor-crawl-long.timeout'))->toBe(3600)
        ->and(config('queue.connections.redis_crawl_long.retry_after'))->toBe(3900);

    $longestSupervisorTimeout = collect((array) config('horizon.defaults'))->max('timeout');

    // Compose не читается из PHP-рантайма; фиксируем согласованный D117 default, чтобы будущая
    // правка Horizon не превысила `stop_grace_period` в dev/prod (4200s).
    $documentedStopGracePeriodSeconds = 4200;

    expect($longestSupervisorTimeout)->toBeLessThan($documentedStopGracePeriodSeconds);
});

function queueJobTimeout(PipelineJob $job): int
{
    $attributes = (new ReflectionClass($job))->getAttributes(Timeout::class);

    if (count($attributes) !== 1) {
        throw new RuntimeException('У '.get_class($job).' должен быть ровно один #[Timeout].');
    }

    return $attributes[0]->newInstance()->timeout;
}
