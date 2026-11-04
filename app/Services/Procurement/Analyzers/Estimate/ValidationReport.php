<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Enums\Procurement\Estimate\EstimateValidationStatus;
use App\Enums\Procurement\Estimate\RowQualityReason;

/**
 * Типизированный результат детерминированной валидации `EstimateValidator::validate`:
 * итоговый статус, аномальные строки и пояснения для публикации и операторского ревью.
 *
 * - status: итоговый вердикт (valid/suspicious/invalid).
 * - anomalousRowIndexes: row_index строк, вовлечённых в обнаруженные аномалии (арифметика/
 *   монотонность/дубли) — кандидаты точечного re-run (`RerunRangeAction`).
 * - rowReasons/rowNotes: та же аномалия, что и `anomalousRowIndexes`, но привязанная к КОНКРЕТНОЙ
 *   причине по `row_index` (research/12-uncertainty-model.md §3.2/§3.3) — без неё построчную
 *   причину пришлось бы восстанавливать разбором текста `notes` (хрупко и запрещено). Обе
 *   карты заполняются В ОДНОМ месте — на строке `$rowReasons[$i][] =...; $rowNotes[$i][] = $note;`
 *   — поэтому список на одном и том же `row_index` идёт парами 1:1 по индексу списка, готовыми к
 *   переносу в форму хранения `quality.validator.reasons`/`quality.validator.notes` без парсинга.
 * - notes: человекочитаемые пояснения найденных аномалий (для `analysis.result`/операторского
 *   ревью — не для машинной обработки; тот же список идёт на уровень СМЕТЫ,
 *   `prc_estimates.validation_notes`).
 */
final readonly class ValidationReport
{
    /**
     * @param  list<int>  $anomalousRowIndexes
     * @param  array<int, list<RowQualityReason>>  $rowReasons
     * @param  array<int, list<string>>  $rowNotes
     * @param  list<string>  $notes
     */
    public function __construct(
        public EstimateValidationStatus $status,
        public array $anomalousRowIndexes = [],
        public array $rowReasons = [],
        public array $rowNotes = [],
        public array $notes = [],
    ) {}
}
