<?php

declare(strict_types=1);

namespace App\Enums\Llm;

/**
 * Кросс-документные назначения вызова — не принадлежат ни одному типу
 * документа. Назначения извлечения объявляет анализатор типа
 * (`App\Enums\Procurement\Analyzers\<Type>\*LlmPurpose`).
 */
enum CoreLlmPurpose: string implements LlmPurpose
{
    case Screening = 'screening';
    case Eval = 'eval';
    case Shadow = 'shadow';
    case PromptCritic = 'prompt_critic';

    /**
     * Паритет с `LlmRouteResolver::DEFAULT_TEMPERATURE_BY_PURPOSE` (contract §4.4): все восемь
     * кейсов обоих enum'ов сегодня дают `0.0` — константа снимается, значения не меняются.
     */
    public function defaultTemperature(): float
    {
        return 0.0;
    }
}
