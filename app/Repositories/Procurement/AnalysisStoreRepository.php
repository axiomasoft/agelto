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
     * Найти активный анализ версии документа на заданном шаге или создать новый (уникальность —
     * partial unique `(document_version_id, step) WHERE superseded_at IS NULL AND is_shadow = false`,
     * миграция `2026_07_04_100025`). `Analysis::query()->firstOrCreate()` намеренно НЕ используется:
     * без ограничения на `active()` он вернул бы superseded-запись той же пары (P9.1, ревью K п.13).
     */
    public function firstOrCreate(int $documentVersionId, AnalysisStep $step): Analysis
    {
        $analysis = Analysis::query()
            ->active()
            ->where('document_version_id', $documentVersionId)
            ->where('step', $step)
            ->first();

        return $analysis ?? Analysis::query()->create([
            'document_version_id' => $documentVersionId,
            'step' => $step,
            'status' => AnalysisStatus::Pending,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Analysis $analysis, array $attributes): void
    {
        $analysis->update($attributes);
    }
}
