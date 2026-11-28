<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Estimate;

/**
 * Причина попадания строки в буфер incomplete_items (docs/specs/v2/08-estimate-extraction.md §4б).
 * Семантика границы разная в двух путях извлечения (row-range vs windowed) — сам факт причины
 * общий для обоих.
 * - SplitByPageEnd: строка началась внизу страницы/диапазона, код расценки есть, но числовые
 *   показатели физически обрываются на границе — модель не должна их додумывать.
 * - OrphanNumbersAtPageStart: страница/диапазон начинается с голых цифр без кода расценки сверху —
 *   продолжение предыдущей строки.
 */
enum IncompleteReason: string
{
    case SplitByPageEnd = 'split_by_page_end';
    case OrphanNumbersAtPageStart = 'orphan_numbers_at_page_start';
}
