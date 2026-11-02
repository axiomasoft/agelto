<?php

declare(strict_types=1);

namespace App\Enums\Llm;

/**
 * Носитель назначения LLM-вызова (`llm_provider_calls.purpose`/`llm_routes.purpose`), реализуемый
 * ядерным `CoreLlmPurpose` (кросс-документные назначения) и типовыми enum'ами анализаторов
 * (`App\Enums\Procurement\Analyzers\<Type>\*LlmPurpose`, contract P2.1 §4.1).
 */
interface LlmPurpose
{
    public string $value { get; }

    public function defaultTemperature(): float;
}
