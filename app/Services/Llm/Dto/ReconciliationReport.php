<?php

declare(strict_types=1);

namespace App\Services\Llm\Dto;

/**
 * Отчёт сверки нашего трекинга с ручной выгрузкой биллинга провайдера (`BillingReconciler`).
 * `exportEmpty` различает «выгрузка не несёт данных за период» от «расхождение 0 %»;
 * пустая серия не считается успешной сверкой. `thresholdPercent`/`exceedsThreshold`
 * вычислены сервисом из `config('ai.reconcile.threshold_percent')`, потребитель (команда) их не
 * пересчитывает.
 */
final readonly class ReconciliationReport
{
    /** @param list<ReconciliationRow> $rows */
    public function __construct(
        public array $rows,
        public UsageSignature $signature,
        public int $trackedTotalMicros,
        public int $exportedTotalMicros,
        public int $deltaTotalMicros,
        public float $deltaPercent,
        public float $thresholdPercent,
        public bool $exceedsThreshold,
        public bool $exportEmpty,
        public int $sumAbsoluteDeltaMicros = 0,
        public float $maxDailyDeltaPercent = 0.0,
        public int $daysExceedingThreshold = 0,
        public bool $totalExceedsThreshold = false,
        public bool $dailyExceedsThreshold = false,
    ) {}
}
