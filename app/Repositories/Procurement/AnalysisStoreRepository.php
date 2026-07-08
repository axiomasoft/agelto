<?php

declare(strict_types=1);

namespace App\Repositories\Procurement;

use App\Enums\Procurement\AnalysisStatus;
use App\Enums\Procurement\AnalysisStep;
use App\Models\Procurement\Ai\Analysis;

/**
 * Единственная точка персистентности `prc_ai_analyses` для стадийных процессоров
 * (`ScreeningProcessor`, P5.1; будущие extraction-процессоры, P6) — аналог
 * `DocumentStoreRepository`: сервисы решают, какие атрибуты писать, репозиторий только пишет.
 */
final readonly class AnalysisStoreRepository
{
    /**
     * Найти-или-создать сессию анализа версии документа на заданном шаге (уникальность —
     * `(document_version_id, step)`, миграция `2026_07_04_100025`).
     */
    public function firstOrCreate(int $documentVersionId, AnalysisStep $step): Analysis
    {
        return Analysis::query()->firstOrCreate(
            attributes: ['document_version_id' => $documentVersionId, 'step' => $step],
            values: ['status' => AnalysisStatus::Pending],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Analysis $analysis, array $attributes): void
    {
        $analysis->update($attributes);
    }
}
