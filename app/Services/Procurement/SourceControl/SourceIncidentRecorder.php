<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Enums\Procurement\Crawler\SourceIncidentSeverity;
use App\Enums\Procurement\Crawler\SourceIncidentType;
use App\Events\Procurement\SourceIncidentRecorded;
use App\Models\Procurement\Crawler\SourceIncident;
use Illuminate\Support\Facades\Log;

/**
 * Единственная точка записи журнала инцидентов: строка `prc_source_incidents` + структурный
 * лог с уровнем по важности + доменное событие `SourceIncidentRecorded`.
 */
final readonly class SourceIncidentRecorder
{
    /**
     * @param  array<string, mixed>|null  $cursor
     * @param  array<string, mixed>|null  $requestParams
     * @param  array<string, mixed>  $context
     */
    public function record(
        int $platformId,
        SourceIncidentType $type,
        string $message,
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
        $severity = $type->severity();

        $incident = SourceIncident::create([
            'platform_id' => $platformId,
            'crawler_id' => $crawlerId,
            'run_id' => $runId,
            'fetch_id' => $fetchId,
            'type' => $type,
            'severity' => $severity,
            'failure_kind' => $failureKind,
            'http_status' => $httpStatus,
            'cursor' => $cursor,
            'request_params' => $requestParams,
            'message' => $message,
            'context' => $context === [] ? null : $context,
            'actor' => $actor,
            'created_at' => now(),
        ]);

        $logContext = array_filter([
            'incident_id' => $incident->id,
            'type' => $type->value,
            'platform_id' => $platformId,
            'crawler_id' => $crawlerId,
            'run_id' => $runId,
            'fetch_id' => $fetchId,
            'failure_kind' => $failureKind?->value,
            'http_status' => $httpStatus,
            'actor' => $actor,
        ], static fn (mixed $value): bool => $value !== null);

        match ($severity) {
            SourceIncidentSeverity::Critical => Log::critical("Источник: {$message}", $logContext),
            SourceIncidentSeverity::Warning => Log::warning("Источник: {$message}", $logContext),
            SourceIncidentSeverity::Info => Log::info("Источник: {$message}", $logContext),
        };

        SourceIncidentRecorded::dispatch($incident->id, $platformId, $type, $severity, $message);

        return $incident;
    }
}
