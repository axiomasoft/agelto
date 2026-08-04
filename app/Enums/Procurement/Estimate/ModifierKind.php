<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Estimate;

/**
 * Род коэффициента позиции (`attributes.modifiers[].kind`, §4.2 `docs/specs/v2/
 * 08-estimate-extraction.md`, `plans/2026.07.26-EST-TRAIN/research/06-schema-decision.md` §5.3/
 * SR-04/SR-05). Расширяемое замкнутое множество — SR-05 (2 файла корпуса, ниже порога N=9)
 * получает представление через новые значения этого enum, а не через отдельный носитель.
 * - Qty: коэффициент к количеству.
 * - Price: коэффициент к стоимости.
 * - Correction: поправочный коэффициент.
 * - Winter: коэффициент зимнего удорожания.
 * - Recalculation: коэффициент пересчёта.
 * - Other: не распознано ни одним из перечисленных.
 */
enum ModifierKind: string
{
    case Qty = 'qty';
    case Price = 'price';
    case Correction = 'correction';
    case Winter = 'winter';
    case Recalculation = 'recalculation';
    case Other = 'other';
}
