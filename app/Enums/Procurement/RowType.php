<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Тип строки плоского построчного извлечения сметы (docs/specs/v2/08-estimate-extraction.md §3.2/§3.5, D10).
 * Вложенное дерево section→subsection→item у модели НЕ запрашивается — иерархия собирается
 * на бэкенде из row_type + section_title; заголовки разделов и промежуточные итоги — тоже строки,
 * участвуют в сквозной нумерации row_index (§4а).
 * - SectionHeader: заголовок раздела.
 * - SubsectionHeader: заголовок подраздела.
 * - Item: позиция с расценкой.
 * - Subtotal: промежуточный итог раздела.
 * - SummaryRow: строка свода КС-3 (стоимость с начала года/периода, §5.8, P4.5) — три временных
 *   разреза строки лежат в `attributes.amount_*` (§4.1/§4.2 DTO `RowAttributes`), не в отдельных
 *   полях самой строки.
 */
enum RowType: string
{
    case SectionHeader = 'section_header';
    case SubsectionHeader = 'subsection_header';
    case Item = 'item';
    case Subtotal = 'subtotal';
    case SummaryRow = 'summary_row';
}
