<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Estimate;

/**
 * Тип строки плоского извлечения сметы. Иерархию section/subsection/item backend
 * строит из row_type и section_title; заголовки и итоги входят в сквозной row_index.
 * SectionHeader: раздел. SubsectionHeader: подраздел. Item: позиция с расценкой.
 * Subtotal: промежуточный итог. SummaryRow: свод КС-3; временные разрезы стоимости
 * хранятся в `RowAttributes::amount_*`.
 */
enum RowType: string
{
    case SectionHeader = 'section_header';
    case SubsectionHeader = 'subsection_header';
    case Item = 'item';
    case Subtotal = 'subtotal';
    case SummaryRow = 'summary_row';
}
