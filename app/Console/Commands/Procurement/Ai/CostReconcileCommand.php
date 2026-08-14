<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Ai;

use App\Exceptions\Procurement\OperationFailed;
use App\Services\Llm\BillingReconciler;
use App\Services\Llm\Dto\ReconciliationReport;
use App\Services\Llm\Dto\ReconciliationRow;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Процедура сверки трекинга с биллингом провайдера (P4.4, инцидент D126
 * `2026.07.26-EST-TRAIN`): печатает дельту, НЕ падает и НЕ шлёт алерт (лаг биллинга ≥24ч делает
 * автоматический вердикт недостоверным) — ненулевой код возврата только под `--fail-on-breach`.
 * Периодичность (ежемесячно + после смены модели/маршрута) назначена документом
 * (`docs/03_Dev/runbooks/billing-reconcile.md`), не планировщиком.
 */
#[Description('Сверка нашего трекинга LLM-расхода с ручной выгрузкой биллинга провайдера')]
#[Signature('ai:cost-reconcile
        {--from= : начало периода YYYY-MM-DD, включительно}
        {--to= : конец периода YYYY-MM-DD, исключительно}
        {--file= : путь к нормализованному CSV выгрузки биллинга (колонки date,cost[,currency,service,sku])}
        {--fail-on-breach : ненулевой код возврата, если расхождение выше порога}')]
final class CostReconcileCommand extends Command
{
    public function __construct(private readonly BillingReconciler $reconciler)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $from = $this->parseDateOption('from');
        $to = $this->parseDateOption('to');
        $file = (string) $this->option('file');

        if ($from === null || $to === null || $file === '') {
            $this->error('Обязательны --from=YYYY-MM-DD --to=YYYY-MM-DD --file=<путь к выгрузке>.');

            return self::FAILURE;
        }

        try {
            $report = $this->reconciler->reconcile($from, $to, $file);
        } catch (OperationFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($report->exportEmpty) {
            $this->warn('Выгрузка не содержит строк за указанный период — сравнить не с чем.');
            $this->line($report->signature->toLine());

            return self::SUCCESS;
        }

        $this->renderTable($report);
        $this->renderTotals($report);
        $this->line($report->signature->toLine());

        if (! $report->exceedsThreshold) {
            $this->info(sprintf('Расхождение %.2f%% в пределах порога %.2f%%.', $report->deltaPercent, $report->thresholdPercent));

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            'Расхождение %.2f%% превышает порог %.2f%% (config(ai.reconcile.threshold_percent)) — см. docs/03_Dev/runbooks/billing-reconcile.md.',
            $report->deltaPercent,
            $report->thresholdPercent,
        ));

        return $this->option('fail-on-breach') ? self::FAILURE : self::SUCCESS;
    }

    private function renderTable(ReconciliationReport $report): void
    {
        $this->table(
            ['day', 'наш трекинг', 'выгрузка', 'дельта', 'дельта %'],
            array_map(fn (ReconciliationRow $row): array => [
                $row->day->toDateString(),
                $this->formatUsd($row->trackedMicros),
                $this->formatUsd($row->exportedMicros),
                $this->formatUsd($row->deltaMicros),
                sprintf('%.2f%%', $row->deltaPercent),
            ], $report->rows),
        );
    }

    private function renderTotals(ReconciliationReport $report): void
    {
        $this->line(sprintf(
            'Итого: наш трекинг %s, выгрузка %s, дельта %s (%.2f%%)',
            $this->formatUsd($report->trackedTotalMicros),
            $this->formatUsd($report->exportedTotalMicros),
            $this->formatUsd($report->deltaTotalMicros),
            $report->deltaPercent,
        ));
    }

    private function parseDateOption(string $name): ?CarbonImmutable
    {
        $value = $this->option($name);

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('Y-m-d', $value);

            return $date?->startOfDay();
        } catch (InvalidFormatException) {
            return null;
        }
    }

    private function formatUsd(int $micros): string
    {
        return sprintf('$%.4f', $micros / 1_000_000);
    }
}
