<?php

declare(strict_types=1);

namespace App\Services\Llm\Dto;

/**
 * Результат композиции системной части шага (`PromptComposer`, ТЗ 04 §3.2). `systemPart` —
 * байт-в-байт стабильный между вызовами вход `LlmPrompt::systemPart` (implicit-cache префикс,
 * research/E §1); `rulesHash` — `sha1(systemPart)` (`prc_ai_prompts.rules_hash`, инвалидация
 * кэша); `promptVersionSet` — `key => version` участвовавших промтов (`prc_ai_llm_calls.prompt_version_set`,
 * воспроизводимость).
 *
 * @param  array<string, int>  $promptVersionSet
 */
final readonly class ComposedPrompt
{
    public function __construct(
        public string $systemPart,
        public string $rulesHash,
        public array $promptVersionSet,
    ) {}
}
