<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Ai;

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Exceptions\Procurement\Ai\NoActiveAnalysisRun;
use App\Models\Procurement\Ai\Analysis;

/**
 * Единственная точка персистентности `prc_ai_analyses` для стадийных процессоров
 * (`ScreeningProcessor`, P5.1; будущие extraction-процессоры, P6) — аналог
 * `DocumentStoreRepository`: сервисы решают, какие атрибуты писать, репозиторий только пишет.
 *
 * Владеет ТАКЖЕ терминацией `AnalysisRun` (§3.6 root/document-contour.md, Implementation Rule 10
 * P1.6) — единственная точка, через которую проходит КАЖДАЯ запись статуса анализа, поэтому
 * терминальный переход прогона живёт здесь же, а не размазан по `ScreeningJob`/
 * `EstimateExtractionJob`/`ExtractionOrchestrator` (см. `maybeTerminateRun()`).
 */
final readonly class AnalysisStoreRepository
{
    public function __construct(
        private AnalysisRunStoreRepository $runs,
    ) {}

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

        if ($analysis !== null) {
            return $analysis;
        }

        return Analysis::query()->create([
            'document_version_id' => $documentVersionId,
            'analysis_run_id' => $this->resolveActiveRunId($documentVersionId),
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

        $this->maybeTerminateRun($analysis);
    }

    private function resolveActiveRunId(int $documentVersionId): int
    {
        $run = $this->runs->findActive($documentVersionId);

        if ($run === null) {
            throw new NoActiveAnalysisRun($documentVersionId);
        }

        return $run->id;
    }

    /**
     * `Failed`/`Skipped` (любой шаг) — прогон терминируется всегда: `Failed` — провал,
     * `Skipped` — unprocessable-шаг, но прогон отработал штатно (§3.2, обязан быть `succeeded`).
     * `Succeeded` терминирует прогон ТОЛЬКО на шаге `Extraction` (последняя стадия контура сегодня):
     * на `Screening` успех неоднозначен — ветвление (`ScreeningOutcomeHandler`) ещё не решило,
     * продолжится ли прогон извлечением, поэтому терминацию несущих screening-исходов несёт САМ
     * `ScreeningOutcomeHandler` явно. Теневые анализы (`is_shadow`) прогон НЕ терминируют — они
     * делят его с основным (`createShadow()`), и их независимый терминал не значит, что основной
     * прогон завершён.
     */
    private function maybeTerminateRun(Analysis $analysis): void
    {
        if ($analysis->is_shadow) {
            return;
        }

        if ($analysis->status === AnalysisStatus::Failed) {
            $this->runs->markFailed($analysis->analysisRun);

            return;
        }

        if ($analysis->status === AnalysisStatus::Skipped) {
            $this->runs->markSucceeded($analysis->analysisRun);

            return;
        }

        if ($analysis->status === AnalysisStatus::Succeeded && $analysis->step === AnalysisStep::Extraction) {
            $this->runs->markSucceeded($analysis->analysisRun);
        }
    }

    /**
     * Сброс `Analysis` до состояния «свежий анализ» перед повторным прогоном ВНУТРИ `estimate:eval:run`
     * (не путать с {@see createRerun()} — новой строкой боевого re-run): уникальность
     * `(document_version_id, step)` не даёт завести вторую строку на повторный вызов команды, а
     * без явного сброса `ExtractionOrchestrator`/`ScreeningProcessor` резюмировал бы persisted
     * `progress`/`result` прошлого прогона и не сделал бы ни одного нового LLM-вызова.
     * `$documentType` — `AiDocumentType::Estimate` на пути extraction, `null` на пути screening;
     * два лишних обнуления `cache_id`/`cache_expires_at` на скрининговом пути — no-op (кэш
     * регистрируется только контуром извлечения, `root/service-layer-map.md` §6).
     */
    public function resetForRerun(Analysis $analysis, int $analysisRunId, ?AiDocumentType $documentType): void
    {
        $this->update($analysis, [
            'status' => AnalysisStatus::Pending,
            'analysis_run_id' => $analysisRunId,
            'document_type' => $documentType?->value,
            'result' => null,
            'progress' => null,
            'provider' => null,
            'model' => null,
            'attempts' => 0,
            'last_error' => null,
            'last_error_at' => null,
            'next_retry_at' => null,
            'failed_permanently' => false,
            'started_at' => null,
            'finished_at' => null,
            'cache_id' => null,
            'cache_expires_at' => null,
        ]);
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
            'analysis_run_id' => $this->resolveActiveRunId($analysis->document_version_id),
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
            'analysis_run_id' => $primary->analysis_run_id,
            'step' => $primary->step,
            'status' => AnalysisStatus::Running,
            'document_type' => $primary->document_type,
            'is_shadow' => true,
            'started_at' => now(),
        ]);
    }
}
