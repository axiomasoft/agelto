<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use App\Enums\Procurement\Crawler\CrawlerEventCode;
use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Enums\Procurement\Crawler\SourceStage;
use App\Models\Procurement\Crawler\SourceIncident;

/**
 * Единственный путь любого события источника (отказ, ожидание, пауза, возобновление):
 * публикация в журнал + структурный лог с одними и теми же тегами. Вызывать вне транзакции
 * обрабатываемого блока/карточки, чтобы запись пережила её откат.
 */
final readonly class SourceEventReporter
{
    public function __construct(
        private SourceIncidentPublisher $publisher,
        private SourceEventLogger $logger,
    ) {}

    /**
     * @param  array<string, mixed>|null  $cursor
     * @param  array<string, mixed>|null  $requestParams
     * @param  array<string, mixed>  $context
     */
    public function report(
        CrawlerEventCode $code,
        int $platformId,
        string $message,
        ?SourceStage $stage = null,
        ?int $crawlerId = null,
        ?int $runId = null,
        ?int $fetchId = null,
        ?SourceFailureKind $failureKind = null,
        ?int $httpStatus = null,
        ?array $cursor = null,
        ?array $requestParams = null,
        array $context = [],
        ?string $actor = null,
    ): SourceIncident {
        $context = array_filter(['stage' => $stage?->value] + $context, static fn (mixed $value): bool => $value !== null);

        $incident = $this->publisher->publish($code, $message, [
            'platform_id' => $platformId,
            'crawler_id' => $crawlerId,
            'run_id' => $runId,
            'fetch_id' => $fetchId,
            'failure_kind' => $failureKind,
            'http_status' => $httpStatus,
            'cursor' => $cursor,
            'request_params' => $requestParams,
            'context' => $context === [] ? null : $context,
            'actor' => $actor,
        ]);

        $this->logger->log($code, $message, [
            'incident_id' => $incident->id,
            'platform_id' => $platformId,
            'crawler_id' => $crawlerId,
            'run_id' => $runId,
            'fetch_id' => $fetchId,
            'failure_kind' => $failureKind?->value,
            'http_status' => $httpStatus,
            'cursor' => $cursor,
            'request_params' => $requestParams,
            'actor' => $actor,
        ] + $context);

        return $incident;
    }
}
