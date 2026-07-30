<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Analyzers\Estimate;

use App\Enums\Llm\LlmPurpose;

/**
 * Собственные назначения вызова анализатора сметы (contract P2.1 §4.1) — принадлежат
 * `AiDocumentType::Estimate`, ядро о них не знает (D14/D7).
 */
enum EstimateLlmPurpose: string implements LlmPurpose
{
    case Discovery = 'discovery';
    case ExtractPassport = 'extract_passport';
    case ExtractRows = 'extract_rows';
    case ExtractTotals = 'extract_totals';

    /**
     * Паритет с `LlmRouteResolver::DEFAULT_TEMPERATURE_BY_PURPOSE` (contract §4.4): все восемь
     * кейсов обоих enum'ов сегодня дают `0.0` — константа снимается, значения не меняются.
     */
    public function defaultTemperature(): float
    {
        return 0.0;
    }
}
