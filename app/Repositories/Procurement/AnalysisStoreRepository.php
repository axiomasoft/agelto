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

    /**
     * Новый `pending`-анализ повторного прогона (`RerunAnalysisAction`, P9.2): та же версия
     * документа и тот же шаг, что у `$analysis`, `parent_analysis_id` — связь с прогоном,
     * который заменяет (D24). Старый `$analysis` в `superseded_at` НЕ переводит — вызывающий код
     * ОБЯЗАН пометить старый анализ `superseded_at` ДО вызова этого метода: partial unique
     * `prc_ai_analyses_active_version_step_unique` — обычный (не `DEFERRABLE`) индекс, проверяется
     * немедленно на `INSERT`, а не в конце транзакции — при обратном порядке два активных анализа
     * на одну пару `(document_version_id, step)` существовали бы одновременно и `INSERT` упал бы.
     */
    public function createRerun(Analysis $analysis): Analysis
    {
        return Analysis::query()->create([
            'document_version_id' => $analysis->document_version_id,
            'step' => $analysis->step,
            'status' => AnalysisStatus::Pending,
            'document_type' => $analysis->document_type,
            'parent_analysis_id' => $analysis->id,
        ]);
    }

    /**
     * Теневой анализ (`is_shadow=true`, P9.4, D24) — та же версия документа и шаг, что у
     * основного `$primary`, но ВНЕ partial unique `(document_version_id, step) WHERE
     * superseded_at IS NULL AND is_shadow = false` (миграция `2026_07_04_100025`) — свободно
     * сосуществует с активным боевым анализом. `parent_analysis_id` НЕ используется (то поле —
     * канон истории перепрогонов, не shadow-связи, D24 докблок `Analysis`): корреляция с
     * основным прогоном — через `document_version_id`+`step` на момент диспатча.
     */
    public function createShadow(Analysis $primary): Analysis
    {
        return Analysis::query()->create([
            'document_version_id' => $primary->document_version_id,
            'step' => $primary->step,
            'status' => AnalysisStatus::Running,
            'document_type' => $primary->document_type,
            'is_shadow' => true,
            'started_at' => now(),
        ]);
    }
}
