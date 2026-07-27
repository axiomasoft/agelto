<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

/**
 * Тип строки концевика сметы (`prc_estimate_totals.kind`, D101,
 * `plans/2026.07.26-EST-TRAIN/research/06-schema-decision.md` §5.7). Детерминированное
 * доопределение по `label` этим планом не строится (долг, §7) — колонка пуста при молчании модели.
 * - Overhead: накладные расходы (НР).
 * - Profit: сметная прибыль (СП).
 * - Vat: НДС.
 * - Total: итоговая строка («Итого», «Всего»).
 * - Additional: дополнительное начисление по главе (SR-11).
 * - Other: не распознано ни одним из перечисленных.
 */
enum TotalKind: string
{
    case Overhead = 'overhead';
    case Profit = 'profit';
    case Vat = 'vat';
    case Total = 'total';
    case Additional = 'additional';
    case Other = 'other';
}
