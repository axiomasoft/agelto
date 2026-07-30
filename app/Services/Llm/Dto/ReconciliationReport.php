<?php

declare(strict_types=1);

namespace App\Services\Llm\Dto;

/**
 * Отчёт сверки нашего трекинга с ручной выгрузкой биллинга провайдера (`BillingReconciler`, P4.4).
 * `exportEmpty` различает «выгрузка не несёт данных за период» от «расхождение 0 %» (Implementation
 * Rule §1 карты — молчаливая пустая серия запрещена); `thresholdPercent`/`exceedsThreshold`
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
    ) {}
}
