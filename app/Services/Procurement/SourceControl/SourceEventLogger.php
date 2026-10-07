<?php

declare(strict_types=1);

namespace App\Services\Procurement\SourceControl;

use App\Enums\Procurement\Crawler\CrawlerEventCode;
use App\Enums\Procurement\Crawler\SourceIncidentSeverity;
use Illuminate\Support\Facades\Log;

/**
 * Структурный лог событий краулера: уровень из кода, сообщение `"<КОД>: <текст>"`, теги в
 * контексте (без пустых). Пишет в штатный канал Laravel (`LOG_CHANNEL`; в проде — `json`),
 * своего канала и форматтера нет.
 */
final readonly class SourceEventLogger
{
    /** @param array<string, mixed> $tags */
    public function log(CrawlerEventCode $code, string $message, array $tags): void
    {
        Log::log(match ($code->severity()) {
            SourceIncidentSeverity::Critical => 'critical',
            SourceIncidentSeverity::Error => 'error',
            SourceIncidentSeverity::Warning => 'warning',
            SourceIncidentSeverity::Info => 'info',
        }, "{$code->value}: {$message}", array_filter(
            ['code' => $code->value] + $tags,
            static fn (mixed $value): bool => $value !== null && $value !== [],
        ));
    }
}
