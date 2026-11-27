<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate\Diff;

use App\Services\Procurement\Analyzers\Estimate\Eval\EvalMetrics;

/**
 * Детерминированный отчёт «gold vs предсказание» (`EstimateDiffReport::build`,
 * `docs/specs/v2/08-estimate-extraction.md` §8.6). Состав контракта закрыт; новый ключ требует согласованного изменения потребителей:
 * - summary: результат `EvalMetrics::compare` как есть, без переупаковки.
 * - matches: таблица парного сопоставления смет — слот → смета эталона, смета
 *   предсказания, сработавшее правило ({@see EvalMetrics::matchEstimates});
 *   без неё построчный diff многосметного документа нечитаем — человек не знает, что с чем сравнили.
 * - rows / passport / totals: списки `DiffEntry` трёх групп расхождений, адресованные НОМЕРОМ
 *   СЛОТА (`DiffEntry::$estimateIndex`), не позицией в исходном списке `estimates`.
 * - arithmetic: результат `EstimateValidator` ОТДЕЛЬНО по gold и по предсказанию, на слот —
 *   базисно-индексный расчёт законно не сходится и у эталона, поэтому группы не смешиваются.
 * - truncated: `shown`/`total` — усечение `rows` по `limitRows`, никогда не молчаливое.
 * - verdict: одна строка словами.
 */
final readonly class DiffReport
{
    /**
     * @param  array<string, mixed>  $summary
     * @param  list<array{slot: int, gold_index: ?int, predicted_index: ?int, rule: ?string}>  $matches
     * @param  list<DiffEntry>  $rows
     * @param  list<DiffEntry>  $passport
     * @param  list<DiffEntry>  $totals
     * @param  list<array{estimate_index: int, predicted: array{status: string, anomalous_row_indexes: list<int>, notes: list<string>}|null, gold: array{status: string, anomalous_row_indexes: list<int>, notes: list<string>}|null}>  $arithmetic
     * @param  array{shown: int, total: int}  $truncated
     */
    public function __construct(
        public array $summary,
        public array $matches,
        public array $rows,
        public array $passport,
        public array $totals,
        public array $arithmetic,
        public array $truncated,
        public string $verdict,
    ) {}

    /**
     * @return array{summary: array<string, mixed>, matches: list<array<string, mixed>>, rows: list<array<string, mixed>>, passport: list<array<string, mixed>>, totals: list<array<string, mixed>>, arithmetic: list<array<string, mixed>>, truncated: array{shown: int, total: int}, verdict: string}
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'matches' => $this->matches,
            'rows' => array_map(static fn (DiffEntry $entry): array => $entry->toArray(), $this->rows),
            'passport' => array_map(static fn (DiffEntry $entry): array => $entry->toArray(), $this->passport),
            'totals' => array_map(static fn (DiffEntry $entry): array => $entry->toArray(), $this->totals),
            'arithmetic' => $this->arithmetic,
            'truncated' => $this->truncated,
            'verdict' => $this->verdict,
        ];
    }
}
