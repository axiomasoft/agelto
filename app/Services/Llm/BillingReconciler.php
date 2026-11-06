<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Exceptions\Llm\BillingExportUnreadable;
use App\Services\Llm\Contracts\UsageSource;
use App\Services\Llm\Dto\ReconciliationReport;
use App\Services\Llm\Dto\ReconciliationRow;
use App\Services\Llm\Dto\UsageQuery;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;

/**
 * Сверка нашего трекинга (`UsageSource`) с ручной выгрузкой биллинга провайдера (
 * инцидент биллинга). Вход — НОРМАЛИЗОВАННЫЙ CSV нашего формата, а не «выгрузка
 * Google как есть»: схема Cloud Billing — функция
 * группировки отчёта оператором, а не контракт. Наш расход берётся ТОЛЬКО через `UsageSource`
 * (то же окно, та же ось «день»), чтобы сверка проверяла ровно то, что показывает дашборд.
 */
final readonly class BillingReconciler
{
    /** @var array<string, list<string>> роль колонки → её допустимые заголовки (короткий список алиасов) */
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
        $fromDay = $this->parseStrictDay($from->format('Y-m-d'), 'граница начала периода');
        $toDay = $this->parseStrictDay($to->format('Y-m-d'), 'граница окончания периода');
        if ($fromDay->gte($toDay)) {
            throw new BillingExportUnreadable(
                "Некорректный период сверки: начало ({$fromDay->toDateString()}) должно быть строго меньше окончания ({$toDay->toDateString()}).",
            );
        }

        $exportedByDay = $this->parseExport($exportPath, $fromDay, $toDay);

        $usage = $this->usageSource->usage(new UsageQuery(from: $fromDay, to: $toDay, byDay: true));

        $trackedByDay = [];

        foreach ($usage->rows as $row) {
            if ($row->day === null) {
                continue;
            }

            $key = $this->parseStrictDay($row->day->format('Y-m-d'), 'трекинг')->toDateString();
            if ($row->costMicros < 0) {
                throw new BillingExportUnreadable("Отрицательная сумма трекинга за {$key}.");
            }
            $trackedByDay[$key] = $this->checkedAdd($trackedByDay[$key] ?? 0, $row->costMicros, "суточной суммы трекинга за {$key}");
        }

        $days = array_unique([...array_keys($trackedByDay), ...array_keys($exportedByDay)]);
        sort($days);

        $configuredThreshold = config('ai.reconcile.threshold_percent');
        $threshold = is_int($configuredThreshold) || is_float($configuredThreshold) || is_string($configuredThreshold)
            ? (float) $configuredThreshold
            : 0.0;

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

            $date = $this->parseStrictDay($day, 'отчёт');

            $rows[] = new ReconciliationRow(
                day: $date,
                trackedMicros: $tracked,
                exportedMicros: $exported,
                deltaMicros: $delta,
                deltaPercent: $rowDeltaPercent,
            );

            $trackedTotal = $this->checkedAdd($trackedTotal, $tracked, 'итоговой суммы трекинга');
            $exportedTotal = $this->checkedAdd($exportedTotal, $exported, 'итоговой суммы выгрузки');
            $sumAbsoluteDeltaMicros = $this->checkedAdd($sumAbsoluteDeltaMicros, $absDelta, 'суммы абсолютных дневных расхождений');
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
            $header = fgetcsv($handle, escape: '\\');

            if ($header === false || $header === [null]) {
                throw new BillingExportUnreadable("Выгрузка пуста — нет строки заголовка: {$exportPath}.");
            }

            $headerCells = $this->stringCells($header);
            $columns = $this->resolveColumns($headerCells);
            $columnCount = count($headerCells);
            $byDay = [];
            $rowNumber = 1;

            while (($record = fgetcsv($handle, escape: '\\')) !== false) {
                $rowNumber++;
                if ($record === [null]) {
                    continue;
                }

                $record = $this->stringCells($record);
                if (count($record) > $columnCount) {
                    throw new BillingExportUnreadable(
                        "Строка {$rowNumber} содержит лишние колонки (".count($record)." вместо {$columnCount}).",
                    );
                }
                if (! array_key_exists($columns['date'], $record) || ! array_key_exists($columns['cost'], $record)) {
                    throw new BillingExportUnreadable("Строка {$rowNumber} не содержит обязательных ячеек date/cost.");
                }

                $this->guardCurrency($record, $columns);

                $rawDate = trim((string) $record[$columns['date']]);
                $day = $this->parseStrictDay($rawDate, "строка {$rowNumber}");

                if ($day->lt($from) || $day->gte($to)) {
                    continue;
                }

                $rawCost = str_replace(',', '.', trim((string) $record[$columns['cost']]));
                if (preg_match('/^(?:0|[1-9]\d{0,11})(?:\.\d{1,18})?$/D', $rawCost) !== 1 || ! is_numeric($rawCost)) {
                    throw new BillingExportUnreadable("Некорректная сумма «{$rawCost}» в выгрузке за {$rawDate} (строка {$rowNumber}).");
                }
                $scaled = bcmul($rawCost, '1000000', 12);
                $rounded = bcadd($scaled, '0.5', 0);
                if (bccomp($rounded, (string) PHP_INT_MAX, 0) > 0 || bccomp($rounded, '0', 0) < 0) {
                    throw new BillingExportUnreadable("Переполнение суммы «{$rawCost}» в выгрузке за {$rawDate} (строка {$rowNumber}).");
                }
                $costMicros = (int) $rounded;
                $key = $day->toDateString();
                $byDay[$key] = $this->checkedAdd($byDay[$key] ?? 0, $costMicros, "суточной суммы выгрузки за {$key}");
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
            '(шаг runbook: docs/dev/runbooks/billing-reconcile.md).',
        );
    }

    /**
     * @param  list<string>  $header
     * @return array<string, int>
     */
    private function resolveColumns(array $header): array
    {
        $columns = [];
        $seenHeaders = [];
        $duplicates = [];
        $unknown = [];

        foreach ($header as $index => $cell) {
            $role = $this->roleFor(strtolower(trim((string) $cell)));

            if ($role === null) {
                $unknown[] = $cell;

                continue;
            }

            if (isset($columns[$role])) {
                $duplicates[] = sprintf('%s (%s, %s)', $role, $seenHeaders[$role], $cell);

                continue;
            }

            $columns[$role] = $index;
            $seenHeaders[$role] = $cell;
        }

        if ($unknown !== []) {
            throw new BillingExportUnreadable(sprintf(
                'Незнакомые колонки в заголовке выгрузки: %s. Ожидаются: %s.',
                implode(', ', $unknown),
                implode(', ', array_keys(self::COLUMN_ALIASES)),
            ));
        }

        if ($duplicates !== []) {
            throw new BillingExportUnreadable(sprintf(
                'Дублирующиеся или конфликтующие колонки в заголовке выгрузки: %s.',
                implode('; ', $duplicates),
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

    private function parseStrictDay(string $rawDate, string $context): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $rawDate) !== 1) {
            throw new BillingExportUnreadable("Не удалось разобрать дату «{$rawDate}» ({$context}) — ожидается строгий формат YYYY-MM-DD.");
        }

        $timezone = config('app.timezone');
        $tz = is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';

        try {
            $parsedDay = CarbonImmutable::createFromFormat('!Y-m-d', $rawDate, $tz);
        } catch (InvalidFormatException) {
            throw new BillingExportUnreadable("Не удалось разобрать дату «{$rawDate}» ({$context}) — ожидается существующая календарная дата YYYY-MM-DD.");
        }

        $errors = CarbonImmutable::getLastErrors();
        if (! $parsedDay instanceof CarbonImmutable
            || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
            || $parsedDay->format('Y-m-d') !== $rawDate) {
            throw new BillingExportUnreadable("Несуществующая календарная дата «{$rawDate}» ({$context}).");
        }

        return $parsedDay->startOfDay();
    }

    private function checkedAdd(int $left, int $right, string $context): int
    {
        if ($right < 0 || $left > PHP_INT_MAX - $right) {
            throw new BillingExportUnreadable("Переполнение {$context}.");
        }

        return $left + $right;
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

    /**
     * @param  list<string|null>  $cells
     * @return list<string>
     */
    private function stringCells(array $cells): array
    {
        return array_map(static fn (?string $cell): string => $cell ?? '', $cells);
    }
}
