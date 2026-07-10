<?php

declare(strict_types=1);

namespace App\Services\Llm\Dto;

/**
 * `responseJsonSchema` для structured output (ТЗ 04 §2) — обёртка над массивом от
 * `JsonSchemaGenerator` (P2.3). Держим доменной DTO, а не сырым `array`/фреймворковым
 * `Illuminate\JsonSchema\JsonSchema` (тот — для миграций/валидации запросов, другая семантика):
 * контракт `LlmProvider` не должен зависеть ни от Prism, ни от Illuminate JSON Schema напрямую.
 */
final readonly class JsonSchema
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(
        public array $schema,
    ) {}
}
