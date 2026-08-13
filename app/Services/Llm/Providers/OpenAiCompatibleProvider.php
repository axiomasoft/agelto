<?php

declare(strict_types=1);

namespace App\Services\Llm\Providers;

use App\Enums\Llm\FinishReason;
use App\Services\Llm\Contracts\LlmProvider;
use App\Services\Llm\Dto\JsonSchema;
use App\Services\Llm\Dto\LlmOptions;
use App\Services\Llm\Dto\LlmPrompt;
use App\Services\Llm\Dto\LlmResponse;
use App\Services\Llm\Dto\LlmStructuredResponse;
use App\Services\Llm\Dto\LlmUsage;
use App\Support\SecretValue;
use Prism\Prism\Enums\FinishReason as PrismFinishReason;
use Prism\Prism\Enums\Provider as PrismProvider;
use Prism\Prism\Enums\StructuredMode;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\RawSchema;
use Prism\Prism\Structured\PendingRequest as StructuredPendingRequest;
use Prism\Prism\Text\PendingRequest as TextPendingRequest;
use Prism\Prism\ValueObjects\Usage as PrismUsage;
use RuntimeException;

/**
 * Транспорт локальной/сторонней LLM через OpenAI-совместимый REST-протокол (vLLM/Ollama/…,
 * P9.4, ТЗ 04 §7) — путь подключения без правки домена (D16). Stateless: `base_url`/`api_key`
 * НЕ хранятся в конструкторе (в отличие от `PrismGeminiProvider`, у которого один провайдер на
 * всё приложение) — маршруты с разными `llm_providers` резолвятся к ОДНОМУ инстансу этого
 * класса из контейнера (`LlmRouteResolver::providerFor()`), поэтому конфигурация приходит per-call
 * через `LlmOptions::$baseUrl`/`$apiKeyEnv` (см. докблок DTO) — `Prism::using(..., $providerConfig)`
 * принимает override без глобального `config/prism.php`.
 *
 * ОГРАНИЧЕНИЕ (Implementation Rules P9.4): адаптер НЕ реализует `SupportsFileUpload` — Files API
 * не является частью OpenAI-совместимого REST-протокола (vLLM/Ollama его не имеют). Документы
 * передаются ТОЛЬКО inline (текст в `userParts`/будущие vision-сообщения) — вызывающий код,
 * рассчитывающий на `fileRefs` (см. `LlmPrompt`), с этим адаптером работать не может; `complete()`/
 * `completeStructured()` бросают исключение, если `fileRefs` непусты, вместо тихой потери файла.
 *
 * Structured output: `usingStructuredMode(StructuredMode::Structured)` — ЯВНО, не `Auto`
 * (default), т.к. `StructuredModeResolver::forModel()` Prism не знает имён локальных моделей
 * (`Qwen3-4B` и т.п.) и упал бы обратно на `Json`-режим (`response_format: json_object` без
 * schema-guided decoding) — для vLLM `structured_outputs`/xgrammar нужен именно `json_schema`
 * (Code Guidance P9.4: `structured_outputs`, НЕ deprecated `guided_json`, это параметр СЕРВЕРА
 * vLLM — на стороне клиента ему соответствует режим `Structured`, не `Auto`/`Json`).
 */
final readonly class OpenAiCompatibleProvider implements LlmProvider
{
    public function complete(LlmPrompt $prompt, LlmOptions $options): LlmResponse
    {
        $this->assertNoFileRefs($prompt);

        $response = $this->textRequest($prompt, $options)->asText();

        return new LlmResponse(
            data: $response->text,
            usage: $this->usageFrom($response->usage),
            finishReason: $this->mapFinishReason($response->finishReason),
            raw: json_encode($response->text, JSON_THROW_ON_ERROR),
        );
    }

    public function completeStructured(LlmPrompt $prompt, JsonSchema $schema, LlmOptions $options): LlmStructuredResponse
    {
        $this->assertNoFileRefs($prompt);

        $response = $this->structuredRequest($prompt, $schema, $options)->asStructured();

        return new LlmStructuredResponse(
            data: $response->structured ?? [],
            usage: $this->usageFrom($response->usage),
            finishReason: $this->mapFinishReason($response->finishReason),
            raw: $response->text,
        );
    }

    private function textRequest(LlmPrompt $prompt, LlmOptions $options): TextPendingRequest
    {
        return Prism::text()
            ->using(PrismProvider::OpenAI, $options->model, $this->providerConfig($options))
            ->withSystemPrompt($prompt->systemPart)
            ->withPrompt(implode("\n\n", $prompt->userParts))
            ->usingTemperature($options->temperature)
            ->withMaxTokens($options->maxTokens);
    }

    private function structuredRequest(LlmPrompt $prompt, JsonSchema $schema, LlmOptions $options): StructuredPendingRequest
    {
        return Prism::structured()
            ->using(PrismProvider::OpenAI, $options->model, $this->providerConfig($options))
            ->usingStructuredMode(StructuredMode::Structured)
            ->withSchema(new RawSchema(name: 'response', schema: $schema->schema))
            ->withSystemPrompt($prompt->systemPart)
            ->withPrompt(implode("\n\n", $prompt->userParts))
            ->usingTemperature($options->temperature)
            ->withMaxTokens($options->maxTokens);
    }

    /**
     * @return array<string, mixed>
     */
    private function providerConfig(LlmOptions $options): array
    {
        if ($options->baseUrl === null || $options->baseUrl === '') {
            throw new RuntimeException('OpenAiCompatibleProvider: у маршрута не задан base_url (llm_providers.base_url).');
        }

        return array_filter(
            [
                'url' => $options->baseUrl,
                'api_key' => $this->apiKey($options->apiKeyEnv),
            ],
            static fn (mixed $value): bool => $value !== null,
        );
    }

    /**
     * Ключ API — только имя env-переменной хранится в БД (`llm_providers.options.api_key_env`,
     * докблок модели `Provider`), сам секрет читается тут. `null` — локальный сервер без
     * аутентификации (типичный случай для vLLM/Ollama за внутренним сетевым периметром).
     */
    private function apiKey(?string $envName): ?string
    {
        if ($envName === null || $envName === '') {
            return null;
        }

        $value = SecretValue::resolve($envName);

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("OpenAiCompatibleProvider: не найден секрет для api_key_env {$envName}.");
        }

        return $value;
    }

    private function assertNoFileRefs(LlmPrompt $prompt): void
    {
        if ($prompt->fileRefs !== []) {
            throw new RuntimeException('OpenAiCompatibleProvider: Files API не поддержан — документ должен передаваться inline (см. докблок класса).');
        }
    }

    private function usageFrom(PrismUsage $usage): LlmUsage
    {
        $cachedTokens = $usage->cacheReadInputTokens ?? 0;

        return new LlmUsage(
            promptTokens: $usage->promptTokens,
            completionTokens: $usage->completionTokens,
            cachedTokens: $cachedTokens,
            totalTokens: $usage->promptTokens + $usage->completionTokens + $cachedTokens,
        );
    }

    private function mapFinishReason(PrismFinishReason $reason): FinishReason
    {
        return match ($reason) {
            PrismFinishReason::Stop, PrismFinishReason::ToolCalls => FinishReason::Stop,
            PrismFinishReason::Length => FinishReason::MaxTokens,
            PrismFinishReason::ContentFilter => FinishReason::Safety,
            PrismFinishReason::Error, PrismFinishReason::Other, PrismFinishReason::Unknown => FinishReason::Other,
        };
    }
}
