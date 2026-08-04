<?php

declare(strict_types=1);

namespace App\Enums\Llm;

use App\Enums\Procurement\Ai\AiDocumentType;

/**
 * Носитель шага композиции системной инструкции (`llm_prompts.step`), реализуемый ядерным
 * `CorePromptStep` (кросс-документные шаги) и типовыми enum'ами анализаторов
 * (`App\Enums\Procurement\Analyzers\<Type>\*PromptStep`, contract P2.1 §4.1). Значение,
 * которое несёт шаг, без типа документа неоднозначно ПО КОНТРАКТУ (§5.4 п.3) — резолв
 * «строка → носитель» идёт только через `DocumentAnalyzerRegistry`, не через этот интерфейс.
 */
interface PromptStep
{
    public string $value { get; }

    /** @return list<PromptStep> Цепочка композиции: global → step-global → сам шаг. */
    public function compositionChain(): array;

    public function documentTypeScope(): AiDocumentType|DocumentTypeScope;
}
