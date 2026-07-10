<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Estimate;

use Spatie\LaravelData\Data;

/**
 * Ответ LLM на шаге extract_rows (docs/specs/v2/08-estimate-extraction.md §3.2; сменяет v1
 * fetch_items). Построчный диапазон (основной путь, §4.1) или постраничное окно внутри сегмента
 * сметы (оконный путь, §4.2) — контракт один и тот же, различается только семантика границ
 * incomplete_items (§4б).
 * - rows: плоский список строк запрошенного диапазона/окна (включая заголовки разделов и
 *   промежуточные итоги, D10).
 * - incomplete_items: буфер незавершённых строк на границе нарезки (§4б).
 */
final class RowsChunkResponse extends Data
{
    /**
     * @param  EstimateRow[]  $rows
     * @param  IncompleteRow[]  $incomplete_items
     */
    public function __construct(
        public array $rows = [],
        public array $incomplete_items = [],
    ) {}
}
