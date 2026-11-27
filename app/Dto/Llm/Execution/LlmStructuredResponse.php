<?php

declare(strict_types=1);

namespace App\Dto\Llm\Execution;

use App\Enums\Llm\FinishReason;

/**
 * Ответ `LlmProvider::completeStructured` — `$data` decoded JSON ПО СХЕМЕ (ТЗ 04 §2), ещё не
 * гидратирован в конкретный Data-класс: гидрация и доменная валидация — ответственность каскада
 * (`StructuredResponseValidator`), а не этот транспортный слой. `finishReason` обязателен
 * (не nullable) — гейт MAX_TOKENS применяется ДО json_decode (research/E §2/§6).
 *
 * @param  array<string, mixed>  $data
 */
final readonly class LlmStructuredResponse
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public array $data,
        public LlmUsage $usage,
        public FinishReason $finishReason,
        public string $raw,
    ) {}
}
