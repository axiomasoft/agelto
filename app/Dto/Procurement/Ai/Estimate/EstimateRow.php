<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Estimate;

use App\Enums\Procurement\RowType;
use Spatie\LaravelData\Data;

/**
 * Плоская строка построчного извлечения (docs/specs/v2/08-estimate-extraction.md §3.2, D10).
 * Вложенное дерево section→subsection→item модель НЕ формирует — иерархия prc_estimate_sections
 * собирается на бэкенде из row_type + section_title. row_type=section_header/subtotal — тоже
 * строки, участвуют в сквозной нумерации row_index (§4а).
 * - row_index: сквозной номер строки в документе (не «№ п/п» исходника) — присваивается
 *   ПОСЛЕДОВАТЕЛЬНО в порядке появления, включая заголовки разделов и промежуточные итоги (§4а).
 * - row_type: section_header/subsection_header/item/subtotal.
 * - section_title: название раздела/подраздела, к которому относится строка (для сборки иерархии
 *   на бэкенде); для строк с row_type=section_header/subsection_header — название самого раздела.
 * - code: шифр расценки (ФЕР/ТЕР), если есть.
 * - name: наименование работы/раздела (обязательно).
 * - unit: единица измерения.
 * - quantity: количество (decimal-строка — конверсия во float запрещена).
 * - unit_price: цена за единицу (decimal-строка).
 * - total_price: стоимость всего по строке (decimal-строка, v1 Alias «Стоимость всего»/«Итого руб»).
 * - resources: ресурсы позиции (материалы/машины/труд/накладные).
 * - modifiers: коэффициенты позиции.
 */
final class EstimateRow extends Data
{
    /**
     * @param  RowResource[]  $resources
     * @param  RowModifier[]  $modifiers
     */
    public function __construct(
        public int $row_index,
        public RowType $row_type,
        public string $name,
        public ?string $section_title = null,
        public ?string $code = null,
        public ?string $unit = null,
        public ?string $quantity = null,
        public ?string $unit_price = null,
        public ?string $total_price = null,
        public array $resources = [],
        public array $modifiers = [],
    ) {}
}
