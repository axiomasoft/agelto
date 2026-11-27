<?php

declare(strict_types=1);

namespace App\Dto\Llm\Prompt;

/**
 * Содержимое, фиксируемое в explicit context cache на этапе СОЗДАНИЯ (ТЗ 04 §2.1: `cachedContent`
 * несовместим с `system_instruction`/`tools` в одном запросе с Gemini — системную часть и файлы
 * закрепляем здесь, а не в `LlmPrompt` обычного вызова). `systemPart` — та же байт-в-байт
 * стабильная системная часть, что и в `LlmPrompt` (implicit-cache инвариант).
 *
 * @param  list<LlmFileHandle>  $fileRefs
 */
final readonly class CacheableContent
{
    /** @param list<LlmFileHandle> $fileRefs */
    public function __construct(
        public string $systemPart,
        public array $fileRefs = [],
    ) {}
}
