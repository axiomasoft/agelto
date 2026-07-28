<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Diff;

/**
 * Одна запись расхождения «gold ↔ предсказание» (P7.3, `docs/specs/v2/08-estimate-extraction.md`
 * §8.6) — самодостаточна (адрес + оба значения), не полагается на соседние записи. Используется
 * во всех трёх группах отчёта (`rows`/`passport`/`totals`, `EstimateDiffReport`):
 * - estimateIndex: позиция сметы в списке `estimates` (та же позиционная адресация, что `EvalMetrics`).
 * - rowIndex: `row_index` строки — только для группы `rows`; `null` у `passport`/`totals`.
 * - kind: `missing` (есть в gold, нет в предсказании) | `extra` (есть в предсказании, нет в gold) |
 *   `field_mismatch` (оба присутствуют, но значение поля расходится).
 * - field: имя расходящегося поля (`field_mismatch`) — `null` у `missing`/`extra` (расхождение
 *   целой строки/начисления, не одного поля).
 * - gold / predicted: значения соответствующей стороны как есть (decimal — строкой, без float).
 */
final readonly class DiffEntry
{
    public function __construct(
        public int $estimateIndex,
        public ?int $rowIndex,
        public string $kind,
        public ?string $field,
        public mixed $gold,
        public mixed $predicted,
    ) {}

    /**
     * @return array{estimate_index: int, row_index: ?int, kind: string, field: ?string, gold: mixed, predicted: mixed}
     */
    public function toArray(): array
    {
        return [
            'estimate_index' => $this->estimateIndex,
            'row_index' => $this->rowIndex,
            'kind' => $this->kind,
            'field' => $this->field,
            'gold' => $this->gold,
            'predicted' => $this->predicted,
        ];
    }
}
