<?php

declare(strict_types=1);

namespace App\Dto\Llm\Prompt;

/**
 * Промт одного вызова (ТЗ 04 §2). `systemPart` — байт-в-байт стабильная между вызовами системная
 * часть (композиция правил + блок схем): база implicit-кэша всех провайдеров (research/E §1)
 * — инвариант держит вызывающий код (`PromptComposer`), не этот DTO.
 *
 * @param  list<string>  $userParts
 * @param  list<LlmFileHandle>  $fileRefs
 */
final readonly class LlmPrompt
{
    /**
     * @param  list<string>  $userParts
     * @param  list<LlmFileHandle>  $fileRefs
     */
    public function __construct(
        public string $systemPart,
        public array $userParts = [],
        public array $fileRefs = [],
    ) {}
}
