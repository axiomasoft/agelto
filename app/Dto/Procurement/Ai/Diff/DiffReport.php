<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Diff;

/**
 * Детерминированный отчёт «gold vs предсказание» (`EstimateDiffReport::build()`, P7.3,
 * `docs/specs/v2/08-estimate-extraction.md` §8.6, D62). Состав ЗАКРЫТ списком (Implementation
 * Rules P7.3) — новый ключ сюда не добавляется без пересмотра ТЗ:
 * - summary: результат `EvalMetrics::compare()` как есть, без переупаковки.
 * - rows / passport / totals: списки `DiffEntry` трёх групп расхождений.
 * - arithmetic: результат `EstimateValidator` ОТДЕЛЬНО по gold и по предсказанию, на смету —
 *   базисно-индексный расчёт законно не сходится и у эталона (D42), поэтому группы не смешиваются.
 * - truncated: `shown`/`total` — усечение `rows` по `limitRows`, никогда не молчаливое.
 * - verdict: одна строка словами.
 */
final readonly class DiffReport
{
    /**
     * @param  array<string, mixed>  $summary
     * @param  list<DiffEntry>  $rows
     * @param  list<DiffEntry>  $passport
     * @param  list<DiffEntry>  $totals
     * @param  list<array{estimate_index: int, predicted: array{status: string, anomalous_row_indexes: list<int>, notes: list<string>}|null, gold: array{status: string, anomalous_row_indexes: list<int>, notes: list<string>}|null}>  $arithmetic
     * @param  array{shown: int, total: int}  $truncated
     */
    public function __construct(
        public array $summary,
        public array $rows,
        public array $passport,
        public array $totals,
        public array $arithmetic,
        public array $truncated,
        public string $verdict,
    ) {}

    /**
     * @return array{summary: array<string, mixed>, rows: list<array<string, mixed>>, passport: list<array<string, mixed>>, totals: list<array<string, mixed>>, arithmetic: list<array<string, mixed>>, truncated: array{shown: int, total: int}, verdict: string}
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'rows' => array_map(static fn (DiffEntry $entry): array => $entry->toArray(), $this->rows),
            'passport' => array_map(static fn (DiffEntry $entry): array => $entry->toArray(), $this->passport),
            'totals' => array_map(static fn (DiffEntry $entry): array => $entry->toArray(), $this->totals),
            'arithmetic' => $this->arithmetic,
            'truncated' => $this->truncated,
            'verdict' => $this->verdict,
        ];
    }
}
