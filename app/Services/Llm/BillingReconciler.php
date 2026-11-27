<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Dto\Llm\Reporting\ReconciliationReport;
use App\Dto\Llm\Reporting\ReconciliationRow;
use App\Dto\Llm\Reporting\UsageQuery;
use App\Exceptions\Llm\BillingExportUnreadable;
use App\Services\Llm\Contracts\UsageSource;
use Carbon\CarbonInterface;

/**
 * Сверка нашего трекинга (`UsageSource`) с ручной выгрузкой биллинга провайдера (
 * инцидент биллинга). Вход — НОРМАЛИЗОВАННЫЙ CSV нашего формата, а не «выгрузка
 * Google как есть»: схема Cloud Billing — функция
 * группировки отчёта оператором, а не контракт. Наш расход берётся ТОЛЬКО через `UsageSource`
 * (то же окно, та же ось «день»), чтобы сверка проверяла ровно то, что показывает дашборд.
 */
final readonly class BillingReconciler
{
    public function __construct(
        private UsageSource $usageSource,
        private BillingExportCsvReader $csvReader = new BillingExportCsvReader,
    ) {}

    public function reconcile(CarbonInterface $from, CarbonInterface $to, string $exportPath): ReconciliationReport
    {
        $fromDay = BillingExportCsvReader::parseStrictDay($from->format('Y-m-d'), 'граница начала периода');
        $toDay = BillingExportCsvReader::parseStrictDay($to->format('Y-m-d'), 'граница окончания периода');
        if ($fromDay->gte($toDay)) {
            throw new BillingExportUnreadable(
                "Некорректный период сверки: начало ({$fromDay->toDateString()}) должно быть строго меньше окончания ({$toDay->toDateString()}).",
            );
        }

        $exportedByDay = $this->csvReader->readByDay($exportPath, $fromDay, $toDay);

        $usage = $this->usageSource->usage(new UsageQuery(from: $fromDay, to: $toDay, byDay: true));

        $trackedByDay = [];

        foreach ($usage->rows as $row) {
            if ($row->day === null) {
                continue;
            }

            $key = BillingExportCsvReader::parseStrictDay($row->day->format('Y-m-d'), 'трекинг')->toDateString();
            if ($row->costMicros < 0) {
                throw new BillingExportUnreadable("Отрицательная сумма трекинга за {$key}.");
            }
            $trackedByDay[$key] = BillingExportCsvReader::checkedAdd($trackedByDay[$key] ?? 0, $row->costMicros, "суточной суммы трекинга за {$key}");
        }

        $days = array_unique([...array_keys($trackedByDay), ...array_keys($exportedByDay)]);
        sort($days);

        $configuredThreshold = config('ai.reconcile.threshold_percent');
        if (! is_numeric($configuredThreshold)) {
            throw new \UnexpectedValueException('ai.reconcile.threshold_percent must be numeric.');
        }
        $threshold = (float) $configuredThreshold;

        $rows = [];
        $trackedTotal = 0;
        $exportedTotal = 0;
        $sumAbsoluteDeltaMicros = 0;
        $maxDailyDeltaPercent = 0.0;
        $daysExceedingThreshold = 0;

        foreach ($days as $day) {
            $tracked = $trackedByDay[$day] ?? 0;
            $exported = $exportedByDay[$day] ?? 0;
            $delta = $tracked - $exported;
            $absDelta = $delta < 0 ? -$delta : $delta;
            $rowDeltaPercent = $this->percent($delta, $tracked, $exported);

            $date = BillingExportCsvReader::parseStrictDay($day, 'отчёт');

            $rows[] = new ReconciliationRow(
                day: $date,
                trackedMicros: $tracked,
                exportedMicros: $exported,
                deltaMicros: $delta,
                deltaPercent: $rowDeltaPercent,
            );

            $trackedTotal = BillingExportCsvReader::checkedAdd($trackedTotal, $tracked, 'итоговой суммы трекинга');
            $exportedTotal = BillingExportCsvReader::checkedAdd($exportedTotal, $exported, 'итоговой суммы выгрузки');
            $sumAbsoluteDeltaMicros = BillingExportCsvReader::checkedAdd($sumAbsoluteDeltaMicros, $absDelta, 'суммы абсолютных дневных расхождений');
            $maxDailyDeltaPercent = max($maxDailyDeltaPercent, $rowDeltaPercent);
            if ($rowDeltaPercent > $threshold) {
                $daysExceedingThreshold++;
            }
        }

        $deltaTotal = $trackedTotal - $exportedTotal;
        $deltaPercent = $this->percent($deltaTotal, $trackedTotal, $exportedTotal);
        $totalExceedsThreshold = $deltaPercent > $threshold;
        $dailyExceedsThreshold = $daysExceedingThreshold > 0;

        return new ReconciliationReport(
            rows: $rows,
            signature: $usage->signature,
            trackedTotalMicros: $trackedTotal,
            exportedTotalMicros: $exportedTotal,
            deltaTotalMicros: $deltaTotal,
            deltaPercent: $deltaPercent,
            thresholdPercent: $threshold,
            exceedsThreshold: $totalExceedsThreshold || $dailyExceedsThreshold,
            exportEmpty: $exportedByDay === [],
            sumAbsoluteDeltaMicros: $sumAbsoluteDeltaMicros,
            maxDailyDeltaPercent: $maxDailyDeltaPercent,
            daysExceedingThreshold: $daysExceedingThreshold,
            totalExceedsThreshold: $totalExceedsThreshold,
            dailyExceedsThreshold: $dailyExceedsThreshold,
        );
    }

    private function percent(int $delta, int $tracked, int $exported): float
    {
        $base = max($tracked, $exported);

        return $base === 0 ? 0.0 : (abs($delta) / $base) * 100;
    }
}
