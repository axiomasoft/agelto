<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Analyzers\Estimate;

use App\Services\Llm\Contracts\StructurallyValidatable;
use Spatie\LaravelData\Data;

/**
 * Ответ LLM на шаге extract_rows (docs/specs/v2/08-estimate-extraction.md §3.2; сменяет v1
 * fetch_items). Построчный диапазон (основной путь, §4.1) или постраничное окно внутри сегмента
 * сметы (оконный путь, §4.2) — контракт один и тот же, различается только семантика границ
 * incomplete_items (§4б).
 * - rows: плоский список строк запрошенного диапазона/окна (включая заголовки разделов и
 *   промежуточные итоги, D10).
 * - incomplete_items: буфер незавершённых строк на границе нарезки (§4б).
 *
 * Реализует `StructurallyValidatable` (§6.4 контракта P2.1, D107) — монотонность/уникальность
 * `row_index` внутри чанка проверяет сам DTO, а не ядерный `StructuredResponseValidator`.
 */
final class RowsChunkResponse extends Data implements StructurallyValidatable
{
    /**
     * @param  EstimateRow[]  $rows
     * @param  IncompleteRow[]  $incomplete_items
     */
    public function __construct(
        public array $rows = [],
        public array $incomplete_items = [],
    ) {}

    public function structuralError(): ?string
    {
        $seenRowIndexes = [];
        $previousRowIndex = null;

        foreach ($this->rows as $row) {
            if (isset($seenRowIndexes[$row->row_index])) {
                return "Дубликат row_index={$row->row_index} в чанке.";
            }

            $seenRowIndexes[$row->row_index] = true;

            if ($previousRowIndex !== null && $row->row_index <= $previousRowIndex) {
                return "row_index={$row->row_index} нарушает монотонность (предыдущий row_index={$previousRowIndex}).";
            }

            $previousRowIndex = $row->row_index;
        }

        return null;
    }
}
