<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Enums\Procurement\Estimate\EstimateValidationStatus;

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
 * - notes: человекочитаемые пояснения найденных аномалий (для `analysis.result`/операторского
 *   ревью — не для машинной обработки).
 */
final readonly class ValidationReport
{
    /**
     * @param  list<int>  $anomalousRowIndexes
     * @param  list<string>  $notes
     */
    public function __construct(
        public EstimateValidationStatus $status,
        public array $anomalousRowIndexes = [],
        public array $notes = [],
    ) {}
}
