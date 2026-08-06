<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Enums\Procurement\Estimate\EstimateValidationStatus;
use App\Enums\Procurement\Estimate\RowQualityReason;

/**
 * Результат `EstimateValidator::validate()` (`docs/specs/v2/08-estimate-extraction.md` §4.1 п.6,
 * §6 «валидационный промт v1 переносится как ДЕТЕРМИНИРОВАННАЯ проверка в коде»). Второй value-
 * объект сверх буквального Files-списка P6.2 (`StitchReport` там единственный явно назван) —
 * прецедент P3.3/P4.1/P4.2/P6.1: оформление уже описанного в Intent результата («validation_status
 * + список аномальных диапазонов») каноном DTO вместо tuple/массива без формы.
 *
 * - status: итоговый вердикт (valid/suspicious/invalid).
 * - anomalousRowIndexes: row_index строк, вовлечённых в обнаруженные аномалии (арифметика/
 *   монотонность/дубли) — кандидаты точечного re-run (P6.3 `RerunRangeAction`).
 * - rowReasons/rowNotes: та же аномалия, что и `anomalousRowIndexes`, но привязанная к КОНКРЕТНОЙ
 *   причине по `row_index` (P8.4, research/12-uncertainty-model.md §3.2/§3.3) — без неё построчную
 *   причину пришлось бы восстанавливать разбором текста `notes` (хрупко и запрещено, D158). Обе
 *   карты заполняются В ОДНОМ месте — на строке `$rowReasons[$i][] = ...; $rowNotes[$i][] = $note;`
 *   — поэтому список на одном и том же `row_index` идёт парами 1:1 по индексу списка, готовыми к
 *   переносу в форму хранения `quality.validator.reasons`/`quality.validator.notes` без парсинга.
 * - notes: человекочитаемые пояснения найденных аномалий (для `analysis.result`/операторского
 *   ревью — не для машинной обработки; тот же список идёт на уровень СМЕТЫ,
 *   `prc_estimates.validation_notes`, P8.4 §5).
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
