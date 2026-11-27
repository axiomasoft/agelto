<?php

declare(strict_types=1);

namespace App\Enums\Llm;

/**
 * Кросс-документные шаги композиции — не принадлежат ни одному типу
 * документа, `documentTypeScope` всегда `DocumentTypeScope::Any`. Шаги извлечения объявляет
 * анализатор типа (`App\Enums\Procurement\Analyzers\<Type>\*PromptStep`).
 */
enum CorePromptStep: string implements PromptStep
{
    case Global = 'global';
    case ScreeningGlobal = 'screening:global';
    case ScreeningClassify = 'screening:classify';
    case PromptCritic = 'prompt:critic';

    public function compositionChain(): array
    {
        return match ($this) {
            self::Global => [self::Global],
            self::ScreeningGlobal => [self::Global, self::ScreeningGlobal],
            self::ScreeningClassify => [self::Global, self::ScreeningGlobal, self::ScreeningClassify],
            self::PromptCritic => [self::Global, self::PromptCritic],
        };
    }

    public function documentTypeScope(): DocumentTypeScope
    {
        return DocumentTypeScope::Any;
    }
}
