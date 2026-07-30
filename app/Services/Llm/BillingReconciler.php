<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Exceptions\Procurement\BillingExportUnreadable;
use App\Services\Llm\Contracts\UsageSource;
use App\Services\Llm\Dto\ReconciliationReport;
use App\Services\Llm\Dto\ReconciliationRow;
use App\Services\Llm\Dto\UsageQuery;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;

/**
 * Сверка нашего трекинга (`UsageSource`, P4.2) с ручной выгрузкой биллинга провайдера (P4.4,
 * инцидент D126 `2026.07.26-EST-TRAIN`). Вход — НОРМАЛИЗОВАННЫЙ CSV нашего формата, а не «выгрузка
 * Google как есть» (D130, `root/usage-source-contract.md`): схема Cloud Billing — функция
 * группировки отчёта оператором, а не контракт. Наш расход берётся ТОЛЬКО через `UsageSource`
 * (то же окно, та же ось «день»), чтобы сверка проверяла ровно то, что показывает дашборд (P4.3).
 */
final readonly class BillingReconciler
{
    /** @var array<string, list<string>> роль колонки → её допустимые заголовки (D130: короткий список алиасов) */
    private const array COLUMN_ALIASES = [
        'date' => ['date', 'day'],
        'cost' => ['cost', 'amount'],
        'currency' => ['currency'],
        'service' => ['service'],
        'sku' => ['sku'],
    ];

    public function __construct(
        private UsageSource $usageSource,
    ) {}

    public function reconcile(CarbonInterface $from, CarbonInterface $to, string $exportPath): ReconciliationReport
    {
        $exportedByDay = $this->parseExport($exportPath, $from, $to);

        $usage = $this->usageSource->usage(new UsageQuery(from: $from, to: $to, byDay: true));

        $trackedByDay = [];

        foreach ($usage->rows as $row) {
            if ($row->day === null) {
                continue;
            }

            $key = $row->day->toDateString();
            $trackedByDay[$key] = ($trackedByDay[$key] ?? 0) + $row->costMicros;
        }

        $days = array_unique([...array_keys($trackedByDay), ...array_keys($exportedByDay)]);
        sort($days);

        $rows = [];
        $trackedTotal = 0;
        $exportedTotal = 0;

        foreach ($days as $day) {
            $tracked = $trackedByDay[$day] ?? 0;
            $exported = $exportedByDay[$day] ?? 0;
            $delta = $tracked - $exported;

            $rows[] = new ReconciliationRow(
                day: CarbonImmutable::createFromFormat('Y-m-d', $day)->startOfDay(),
                trackedMicros: $tracked,
                exportedMicros: $exported,
                deltaMicros: $delta,
                deltaPercent: $this->percent($delta, $tracked, $exported),
            );

            $trackedTotal += $tracked;
            $exportedTotal += $exported;
        }

        $deltaTotal = $trackedTotal - $exportedTotal;
        $threshold = (float) config('ai.reconcile.threshold_percent');
        $deltaPercent = $this->percent($deltaTotal, $trackedTotal, $exportedTotal);

        return new ReconciliationReport(
            rows: $rows,
            signature: $usage->signature,
            trackedTotalMicros: $trackedTotal,
            exportedTotalMicros: $exportedTotal,
            deltaTotalMicros: $deltaTotal,
            deltaPercent: $deltaPercent,
            thresholdPercent: $threshold,
            exceedsThreshold: $deltaPercent > $threshold,
            exportEmpty: $exportedByDay === [],
        );
    }

    private function percent(int $delta, int $tracked, int $exported): float
    {
        $base = max($tracked, $exported);

        return $base === 0 ? 0.0 : (abs($delta) / $base) * 100;
    }

    /**
     * @return array<string, int> costMicros по дню (`Y-m-d`), в пределах `[from, to)`
     */
    private function parseExport(string $exportPath, CarbonInterface $from, CarbonInterface $to): array
    {
        if (! is_readable($exportPath)) {
            throw new BillingExportUnreadable("Файл выгрузки не найден или недоступен для чтения: {$exportPath}.");
        }

        $handle = fopen($exportPath, 'rb');

        if ($handle === false) {
            throw new BillingExportUnreadable("Файл выгрузки не найден или недоступен для чтения: {$exportPath}.");
        }

        try {
            $header = fgetcsv($handle);

            if ($header === false || $header === [null]) {
                throw new BillingExportUnreadable("Выгрузка пуста — нет строки заголовка: {$exportPath}.");
            }

            $columns = $this->resolveColumns($header);
            $byDay = [];

            while (($record = fgetcsv($handle)) !== false) {
                if ($record === [null]) {
                    continue;
                }

                $this->guardCurrency($record, $columns);

                $rawDate = trim((string) ($record[$columns['date']] ?? ''));

                try {
                    $day = CarbonImmutable::createFromFormat('Y-m-d', $rawDate)->startOfDay();
                } catch (InvalidFormatException) {
                    throw new BillingExportUnreadable("Не удалось разобрать дату «{$rawDate}» в выгрузке — ожидается формат YYYY-MM-DD.");
                }

                if ($day->lt($from) || $day->gte($to)) {
                    continue;
                }

                $rawCost = trim((string) ($record[$columns['cost']] ?? ''));
                $costMicros = (int) round(((float) str_replace(',', '.', $rawCost)) * 1_000_000);
                $key = $day->toDateString();
                $byDay[$key] = ($byDay[$key] ?? 0) + $costMicros;
            }

            return $byDay;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<string>  $record
     * @param  array<string, int>  $columns
     */
    private function guardCurrency(array $record, array $columns): void
    {
        if (! isset($columns['currency'])) {
            return;
        }

        $currency = trim((string) ($record[$columns['currency']] ?? ''));

        if ($currency === '' || strtoupper($currency) === 'USD') {
            return;
        }

        throw new BillingExportUnreadable(
            "Выгрузка несёт валюту «{$currency}» — сверка считает только USD; приведите выгрузку к USD ".
            '(шаг runbook: docs/03_Dev/runbooks/billing-reconcile.md).',
        );
    }

    /**
     * @param  list<string>  $header
     * @return array<string, int>
     */
    private function resolveColumns(array $header): array
    {
        $columns = [];
        $unknown = [];

        foreach ($header as $index => $cell) {
            $role = $this->roleFor(strtolower(trim((string) $cell)));

            if ($role === null) {
                $unknown[] = $cell;

                continue;
            }

            $columns[$role] = $index;
        }

        if ($unknown !== []) {
            throw new BillingExportUnreadable(sprintf(
                'Незнакомые колонки в заголовке выгрузки: %s. Ожидаются: %s.',
                implode(', ', $unknown),
                implode(', ', array_keys(self::COLUMN_ALIASES)),
            ));
        }

        if (! isset($columns['date'], $columns['cost'])) {
            throw new BillingExportUnreadable(sprintf(
                'В заголовке выгрузки нет обязательных колонок date/cost. Найдено: %s.',
                implode(', ', $header),
            ));
        }

        return $columns;
    }

    private function roleFor(string $headerCell): ?string
    {
        foreach (self::COLUMN_ALIASES as $role => $aliases) {
            if (in_array($headerCell, $aliases, true)) {
                return $role;
            }
        }

        return null;
    }
}
