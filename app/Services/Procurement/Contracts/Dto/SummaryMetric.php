<?php

declare(strict_types=1);

namespace App\Services\Procurement\Contracts\Dto;

/**
 * Одна пара «подпись → значение» сводки результата анализа (§3 контракта P2.1, D27).
 *
 * Денежные величины кладутся в `$value` СТРОКОЙ (десятичное представление как есть):
 * `prc_estimates.total_amount` — `decimal:2`, и прогон через `float` даёт классическую потерю
 * копеек на экране, где показывают деньги. Правило общее для любого типа документа, не оговорка
 * про смету.
 */
final readonly class SummaryMetric
{
    public function __construct(
        public string $label,
        public string|int|float $value,
        public ?string $unit = null,
    ) {}
}
