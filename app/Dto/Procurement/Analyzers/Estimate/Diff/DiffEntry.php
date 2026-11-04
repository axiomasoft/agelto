<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate\Diff;

use App\Dto\Procurement\Analyzers\Estimate\RowQuality;
use App\Services\Procurement\Analyzers\Estimate\EvalMetrics;

/**
 * Одна запись расхождения «gold ↔ предсказание» (`docs/specs/v2/08-estimate-extraction.md`
 * §8.6) — самодостаточна (адрес + оба значения), не полагается на соседние записи. Используется
 * во всех трёх группах отчёта (`rows`/`passport`/`totals`, `EstimateDiffReport`):
 * - estimateIndex: номер СЛОТА парного сопоставления смет (`estimate_number` →
 *   `passport.address` → позиция среди оставшихся, {@see EvalMetrics::matchEstimates}) —
 *   НЕ позиция в исходном списке `estimates`; см. `DiffReport::$matches` для самой таблицы
 *   сопоставления (слот → смета эталона, смета предсказания, сработавшее правило).
 * - rowIndex: `row_index` строки — только для группы `rows`; `null` у `passport`/`totals`.
 * - kind: `missing` (есть в gold, нет в предсказании) | `extra` (есть в предсказании, нет в gold) |
 *   `field_mismatch` (оба присутствуют, но значение поля расходится).
 * - field: имя расходящегося поля (`field_mismatch`) — `null` у `missing`/`extra` (расхождение
 *   целой строки/начисления, не одного поля).
 * - gold / predicted: значения соответствующей стороны как есть (decimal — строкой, без float).
 * - predictedQuality: пометка неопределённости строки предсказания (`EstimateRow::$quality`) — КОНТЕКСТ расхождения, а не само расхождение: она объясняет, почему предсказание
 *   пусто («модель не прочитала это поле в источнике»), и потому прикладывается только к
 *   `field_mismatch` и только когда пометка АДРЕСУЕТ это поле ({@see RowQuality::$fields});
 *   `null` — пометки нет либо она про другое поле; у `missing` (строки предсказания нет вовсе) и
 *   `extra` (вся строка уже лежит в `predicted`) контекста не бывает по построению.
 */
final readonly class DiffEntry
{
    /**
     * @param  array<string, mixed>|null  $predictedQuality
     */
    public function __construct(
        public int $estimateIndex,
        public ?int $rowIndex,
        public string $kind,
        public ?string $field,
        public mixed $gold,
        public mixed $predicted,
        public ?array $predictedQuality = null,
    ) {}

    /**
     * @return array{estimate_index: int, row_index: ?int, kind: string, field: ?string, gold: mixed, predicted: mixed, predicted_quality: array<string, mixed>|null}
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
            'predicted_quality' => $this->predictedQuality,
        ];
    }
}
