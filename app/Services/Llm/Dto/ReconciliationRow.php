<?php

declare(strict_types=1);

namespace App\Services\Llm\Dto;

use Carbon\CarbonImmutable;

/**
 * Одна дневная строка сверки (`BillingReconciler`, P4.4): наш трекинг против выгрузки биллинга
 * за КАЛЕНДАРНЫЙ день. `deltaPercent` считается от большего из двух значений, чтобы не делить
 * на ноль, когда один из источников за день пуст.
 */
final readonly class ReconciliationRow
{
    public function __construct(
        public CarbonImmutable $day,
        public int $trackedMicros,
        public int $exportedMicros,
        public int $deltaMicros,
        public float $deltaPercent,
    ) {}
}
