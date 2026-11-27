<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Estimate;

/**
 * Тип строки концевика в `prc_estimate_totals.kind`. По label тип не доопределяется;
 * при отсутствии значения модели колонка остаётся пустой.
 * Overhead: накладные расходы. Profit: сметная прибыль. Vat: НДС.
 * Total: итоговая строка. Additional: дополнительное начисление. Other: нераспознанный вид.
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
