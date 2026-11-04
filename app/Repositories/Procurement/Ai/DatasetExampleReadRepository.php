<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Ai;

use App\Models\Procurement\Ai\DatasetExample;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Чтение `prc_ai_dataset_examples` — `estimate:eval:run`, `estimate:dataset:export`.
 */
final readonly class DatasetExampleReadRepository
{
    /**
     * @param  list<string>  $holdoutCodes
     * @return Collection<int, DatasetExample>
     */
    public function forTask(string $task, string $split, array $holdoutCodes, ?int $limit): Collection
    {
        $query = DatasetExample::query()->where('task', $task)->with('documentVersion');

        if ($split !== 'all') {
            $query->whereHas('fixture', function (Builder $fixtureQuery) use ($holdoutCodes, $split): void {
                $split === 'holdout'
                    ? $fixtureQuery->whereIn('code', $holdoutCodes)
                    : $fixtureQuery->whereNotIn('code', $holdoutCodes);
            });
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * SFT-кандидаты: «экспортируемые» (основание доверия — `source=deterministic` ИЛИ
     * непустой `verified_at`) записи, ПОСЛЕДНЯЯ по `version` на пару (`document_version_id`,
     * `task`) — `DISTINCT ON` (Postgres) при сортировке `document_version_id ASC, version DESC`
     * даёт ровно эту запись без буферизации в PHP. `--source` СУЖАЕТ отбор, основание доверия не
     * отменяет.
     *
     * @param  list<string>  $sources
     * @return LazyCollection<int, DatasetExample>
     */
    public function exportableSft(string $task, array $sources): LazyCollection
    {
        $query = DatasetExample::query()
            ->selectRaw('DISTINCT ON (document_version_id) *')
            ->where('task', $task)
            ->where(function (Builder $trust): void {
                $trust->where('source', 'deterministic')->orWhereNotNull('verified_at');
            })
            ->with(['documentVersion', 'fixture']);

        if ($sources !== []) {
            $query->whereIn('source', $sources);
        }

        return $query->orderBy('document_version_id')->orderByDesc('version')->cursor();
    }

    /**
     * Записи, отсеянные основанием доверия из пула SFT-кандидатов (для счётчика `not_trusted`) — считается ОТДЕЛЬНЫМ агрегатом, а не молча вычитается из `exportableSft`
     * (каждый пропуск должен попадать в отчёт).
     *
     * @param  list<string>  $sources
     */
    public function countNotTrusted(string $task, array $sources): int
    {
        $query = DatasetExample::query()
            ->where('task', $task)
            ->where('source', '!=', 'deterministic')
            ->whereNull('verified_at');

        if ($sources !== []) {
            $query->whereIn('source', $sources);
        }

        return $query->count();
    }

    /**
     * DPO-кандидаты: экспортируемая запись `source=corrected` с непустым
     * `analysis_id` — предшественник резолвится вызывающим через
     * `AnalysisReadRepository::parentOf` (по сохранённому `analysis_id` записи, а не через `activeForVersion`).
     *
     * @param  list<string>  $sources
     * @return LazyCollection<int, DatasetExample>
     */
    public function exportableDpo(string $task, array $sources): LazyCollection
    {
        $query = DatasetExample::query()
            ->where('task', $task)
            ->where('source', 'corrected')
            ->whereNotNull('verified_at')
            ->whereNotNull('analysis_id')
            ->with(['documentVersion', 'fixture', 'analysis']);

        if ($sources !== []) {
            $query->whereIn('source', $sources);
        }

        return $query->orderBy('document_version_id')->orderBy('version')->cursor();
    }

    /**
     * `source=corrected`-записи без сохранённого `analysis_id` (счётчик `no_analysis_id`) —
     * миграция не бэкафиллит существующие строки, только НОВЫЕ пишет `GoldExampleRecorder`.
     *
     * @param  list<string>  $sources
     */
    public function countMissingAnalysisId(string $task, array $sources): int
    {
        $query = DatasetExample::query()
            ->where('task', $task)
            ->where('source', 'corrected')
            ->whereNotNull('verified_at')
            ->whereNull('analysis_id');

        if ($sources !== []) {
            $query->whereIn('source', $sources);
        }

        return $query->count();
    }

    /**
     * `source=corrected`-записи без `verified_at` (счётчик `not_trusted` DPO-пула) — структурно
     * почти не встречается (`GoldExampleRecorder` пишет `verified_at` из `reviewed_at` при
     * КАЖДОМ approve), но пул трактуется по тому же основанию доверия, что SFT.
     *
     * @param  list<string>  $sources
     */
    public function countUntrustedCorrected(string $task, array $sources): int
    {
        $query = DatasetExample::query()
            ->where('task', $task)
            ->where('source', 'corrected')
            ->whereNull('verified_at');

        if ($sources !== []) {
            $query->whereIn('source', $sources);
        }

        return $query->count();
    }
}
