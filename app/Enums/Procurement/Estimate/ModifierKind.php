<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Estimate;

/**
 * Род коэффициента позиции в `attributes.modifiers[].kind`.
 * Новые виды коэффициентов расширяют этот enum, сохраняя общий носитель modifiers.
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
