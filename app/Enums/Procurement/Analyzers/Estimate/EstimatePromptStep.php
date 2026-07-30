<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Analyzers\Estimate;

use App\Enums\Llm\CorePromptStep;
use App\Enums\Llm\PromptStep;
use App\Enums\Procurement\AiDocumentType;

/**
 * Собственные шаги композиции анализатора сметы (contract P2.1 §4.1) — принадлежат
 * `AiDocumentType::Estimate`, ядро о них не знает (D14/D7).
 */
enum EstimatePromptStep: string implements PromptStep
{
    case ExtractionGlobal = 'extraction:global';
    case ExtractionDiscovery = 'extraction:discovery';
    case ExtractionPassport = 'extraction:passport';
    case ExtractionRows = 'extraction:rows';
    case ExtractionTotals = 'extraction:totals';

    public function compositionChain(): array
    {
        return match ($this) {
            self::ExtractionGlobal => [CorePromptStep::Global, self::ExtractionGlobal],
            self::ExtractionDiscovery,
            self::ExtractionPassport,
            self::ExtractionRows,
            self::ExtractionTotals => [CorePromptStep::Global, self::ExtractionGlobal, $this],
        };
    }

    public function documentTypeScope(): AiDocumentType
    {
        return AiDocumentType::Estimate;
    }
}
