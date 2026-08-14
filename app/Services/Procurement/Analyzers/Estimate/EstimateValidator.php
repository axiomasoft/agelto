<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Dto\Procurement\Analyzers\Estimate\EstimateRow;
use App\Dto\Procurement\Analyzers\Estimate\EstimateTotalsBlock;
use App\Dto\Procurement\Analyzers\Estimate\TotalLine;
use App\Enums\Procurement\Estimate\EstimateValidationStatus;
use App\Enums\Procurement\Estimate\RowQualityReason;
use App\Enums\Procurement\Estimate\RowType;
use App\Enums\Procurement\Estimate\TotalKind;

/**
 * Детерминированная (без LLM) арифметическая/структурная валидация извлечённой сметы
 * (`docs/specs/v2/08-estimate-extraction.md` §4.1 п.6, §6 — валидационный промт v1 переносится
 * как проверка в коде, дешевле и надёжнее LLM-вызова, research/E §5 «арифметика — основной
 * сигнал качества»). Чистая функция над DTO (Implementation Rules P6.2) — работает на выходе
 * `EstimateStitcher` (дедуплицированные, отсортированные строки), decimal-сравнение — bcmath
 * (Implementation Rules P6.2: конверсия во float запрещена во всём контракте P2.2).
 *
 * НЕ выбрасывает строки при аномалиях (Code Guidance P6.2: «validation_status=invalid не
 * выбрасывает строки — сохраняем всё, помечаем») — только классифицирует и перечисляет row_index
 * вовлечённых аномалий (кандидаты `RerunRangeAction`, P6.3).
 */
final readonly class EstimateValidator
{
    private const string TOLERANCE = '0.01';

    private const int SCALE = 2;

    /** @var list<string> */
    private const array REQUIRED_ITEM_FIELDS = [
        'source_position_number',
        'code',
        'name',
        'unit',
        'quantity',
    ];

    /**
     * Порог доли расхождения rows_expected/фактически извлечено, после которого неполнота
     * считается СЕРЬЁЗНОЙ (invalid), а не просто подозрительной — калибровка на реальных сметах
     * отложена (P6.3/P7.4 Pending Work, тот же класс решений, что row_range_size/checkpoint_*).
     */
    private const float SEVERE_COMPLETENESS_RATIO = 0.1;

    /**
     * @param  list<EstimateRow>  $rows  выход `EstimateStitcher::stitch()->rows` (уже
     *                                   дедуплицированы, отсортированы по row_index)
     */
    public function validate(int $rowsExpected, array $rows, ?EstimateTotalsBlock $totals): ValidationReport
    {
        usort($rows, static fn (EstimateRow $a, EstimateRow $b): int => $a->row_index <=> $b->row_index);

        $anomalousRowIndexes = [];
        $rowReasons = [];
        $rowNotes = [];
        $notes = [];
        $severe = false;

        $this->checkMonotonicity($rows, $anomalousRowIndexes, $rowReasons, $rowNotes, $notes);
        $severe = $this->checkCompleteness($rowsExpected, $rows, $notes);
        $this->checkRequiredItemFields($rows, $rowReasons, $rowNotes);
        $this->checkSectionArithmetic($rows, $anomalousRowIndexes, $rowReasons, $rowNotes, $notes);
        $this->checkGrandTotal($rows, $totals, $notes);

        $status = match (true) {
            $severe => EstimateValidationStatus::Invalid,
            $notes !== [] => EstimateValidationStatus::Suspicious,
            default => EstimateValidationStatus::Valid,
        };

        return new ValidationReport(
            status: $status,
            anomalousRowIndexes: array_values(array_unique($anomalousRowIndexes)),
            rowReasons: $rowReasons,
            rowNotes: $rowNotes,
            notes: $notes,
        );
    }

    /**
     * Пара `$rowReasons`/`$rowNotes` заполняется ОДНИМ вызовом на строке обнаружения — гарантирует
     * позиционное соответствие 1:1 (P8.4, research/12-uncertainty-model.md §3.2) без последующего
     * сопоставления причины и текста заметки.
     *
     * @param  array<int, list<RowQualityReason>>  $rowReasons
     * @param  array<int, list<string>>  $rowNotes
     */
    private function addRowIssue(array &$rowReasons, array &$rowNotes, int $rowIndex, RowQualityReason $reason, string $note): void
    {
        $rowReasons[$rowIndex][] = $reason;
        $rowNotes[$rowIndex][] = $note;
    }

    /**
     * @param  list<EstimateRow>  $rows
     * @param  list<int>  $anomalousRowIndexes
     * @param  array<int, list<RowQualityReason>>  $rowReasons
     * @param  array<int, list<string>>  $rowNotes
     * @param  list<string>  $notes
     */
    private function checkMonotonicity(array $rows, array &$anomalousRowIndexes, array &$rowReasons, array &$rowNotes, array &$notes): void
    {
        $previousIndex = null;
        $seen = [];

        foreach ($rows as $row) {
            if (isset($seen[$row->row_index])) {
                $anomalousRowIndexes[] = $row->row_index;
                $note = "дубль row_index={$row->row_index} после стичинга (не должен встречаться, defensive-проверка)";
                $notes[] = $note;
                $this->addRowIssue($rowReasons, $rowNotes, $row->row_index, RowQualityReason::NumberingGap, $note);

                continue;
            }

            $seen[$row->row_index] = true;

            if ($previousIndex !== null && $row->row_index > $previousIndex + 1) {
                $anomalousRowIndexes[] = $row->row_index;
                $note = "разрыв нумерации: row_index {$previousIndex} → {$row->row_index}";
                $notes[] = $note;
                $this->addRowIssue($rowReasons, $rowNotes, $row->row_index, RowQualityReason::NumberingGap, $note);
            }

            $previousIndex = $row->row_index;
        }
    }

    /**
     * @param  list<EstimateRow>  $rows
     * @param  list<string>  $notes
     * @return bool true — расхождение серьёзное (invalid-кандидат)
     */
    private function checkCompleteness(int $rowsExpected, array $rows, array &$notes): bool
    {
        if ($rowsExpected <= 0) {
            // Оконный путь (§4а): rows_expected из паспорта части документа не гарантированно
            // точен — не строгий гейт, проверка пропускается (Implementation Rules спеки 08 §4а).
            return false;
        }

        $extracted = count(array_filter($rows, static fn (EstimateRow $row): bool => $row->row_type === RowType::Item));
        $diff = abs($extracted - $rowsExpected);

        if ($diff === 0) {
            return false;
        }

        $ratio = $diff / $rowsExpected;
        $notes[] = "полнота: rows_expected={$rowsExpected}, извлечено позиций={$extracted} (расхождение {$diff})";

        return $ratio > self::SEVERE_COMPLETENESS_RATIO;
    }

    /**
     * Пустота обязательного поля — только построчная пометка: она не добавляет заметку сметы и
     * не меняет её validation_status (P7.4).
     *
     * @param  list<EstimateRow>  $rows
     * @param  array<int, list<RowQualityReason>>  $rowReasons
     * @param  array<int, list<string>>  $rowNotes
     */
    private function checkRequiredItemFields(array $rows, array &$rowReasons, array &$rowNotes): void
    {
        foreach ($rows as $row) {
            if ($row->row_type !== RowType::Item) {
                continue;
            }

            foreach (self::REQUIRED_ITEM_FIELDS as $field) {
                if ($this->hasValue($row->{$field})) {
                    continue;
                }

                $label = EstimateRow::fieldLabel($field);
                $this->addRowIssue(
                    rowReasons: $rowReasons,
                    rowNotes: $rowNotes,
                    rowIndex: $row->row_index,
                    reason: RowQualityReason::RequiredFieldMissing,
                    note: "не заполнено обязательное поле «{$label}»",
                );
            }
        }
    }

    private function hasValue(mixed $value): bool
    {
        if (! is_string($value)) {
            return $value !== null;
        }

        return trim($value) !== '';
    }

    /**
     * Сумма total_price позиций (row_type=Item) внутри раздела/подраздела сверяется со строкой
     * row_type=Subtotal того же раздела (±0.01, bcmath) — накопитель сбрасывается на каждом
     * SectionHeader/SubsectionHeader/Subtotal (граница раздела).
     *
     * @param  list<EstimateRow>  $rows
     * @param  list<int>  $anomalousRowIndexes
     * @param  array<int, list<RowQualityReason>>  $rowReasons
     * @param  array<int, list<string>>  $rowNotes
     * @param  list<string>  $notes
     */
    private function checkSectionArithmetic(array $rows, array &$anomalousRowIndexes, array &$rowReasons, array &$rowNotes, array &$notes): void
    {
        $accumulated = '0';
        $itemIndexesInSection = [];

        foreach ($rows as $row) {
            if (in_array($row->row_type, [RowType::SectionHeader, RowType::SubsectionHeader], strict: true)) {
                $accumulated = '0';
                $itemIndexesInSection = [];

                continue;
            }

            if ($row->row_type === RowType::Item) {
                $accumulated = bcadd($accumulated, $this->decimal($row->total_price_curr), self::SCALE);
                $itemIndexesInSection[] = $row->row_index;

                continue;
            }

            // row_type === Subtotal
            if ($row->total_price_curr === null) {
                continue;
            }

            if (! $this->withinTolerance($accumulated, $this->decimal($row->total_price_curr))) {
                $affectedIndexes = [...$itemIndexesInSection, $row->row_index];
                $anomalousRowIndexes = [...$anomalousRowIndexes, ...$affectedIndexes];
                $note = "арифметика раздела: сумма позиций {$accumulated} ≠ subtotal {$row->total_price_curr} (row_index={$row->row_index})";
                $notes[] = $note;

                foreach ($affectedIndexes as $affectedIndex) {
                    $this->addRowIssue($rowReasons, $rowNotes, $affectedIndex, RowQualityReason::ArithmeticMismatch, $note);
                }
            }

            $accumulated = '0';
            $itemIndexesInSection = [];
        }
    }

    /**
     * Best-effort сверка суммы ВСЕХ позиций с корневой итоговой строкой footer'а (поиск по
     * `kind`/`label`, содержащему «итого»/«всего» — точная таксономия `kind` не зафиксирована
     * спекой на момент P6.2, Known Deviation: мягкий сигнал, не влияет на invalid-статус).
     *
     * @param  list<EstimateRow>  $rows
     * @param  list<string>  $notes
     */
    private function checkGrandTotal(array $rows, ?EstimateTotalsBlock $totals, array &$notes): void
    {
        if ($totals === null) {
            return;
        }

        $grandTotalLine = $this->findGrandTotalLine(array_values($totals->footer));

        if ($grandTotalLine === null) {
            return;
        }

        $sum = '0';

        foreach ($rows as $row) {
            if ($row->row_type === RowType::Item) {
                $sum = bcadd($sum, $this->decimal($row->total_price_curr), self::SCALE);
            }
        }

        if ($grandTotalLine->value === null) {
            return;
        }

        if (! $this->withinTolerance($sum, $this->decimal($grandTotalLine->value))) {
            $notes[] = "итог: сумма позиций {$sum} ≠ «{$grandTotalLine->label}» {$grandTotalLine->value} (best-effort сверка)";
        }
    }

    /**
     * @param  list<TotalLine>  $footer
     */
    private function findGrandTotalLine(array $footer): ?TotalLine
    {
        foreach ($footer as $line) {
            $label = mb_strtolower($line->label);

            if ($line->kind === TotalKind::Total || str_contains($label, 'итого') || str_contains($label, 'всего')) {
                return $line;
            }
        }

        return null;
    }

    /**
     * @param  numeric-string  $a
     * @param  numeric-string  $b
     */
    private function withinTolerance(string $a, string $b): bool
    {
        $diff = bcsub($a, $b, self::SCALE);
        $abs = bccomp($diff, '0', self::SCALE) < 0 ? bcmul($diff, '-1', self::SCALE) : $diff;

        return bccomp($abs, self::TOLERANCE, self::SCALE) <= 0;
    }

    /** @return numeric-string */
    private function decimal(?string $value): string
    {
        $value ??= '0';

        if (! is_numeric($value)) {
            throw new \UnexpectedValueException("Expected decimal value, got {$value}.");
        }

        return $value;
    }
}
