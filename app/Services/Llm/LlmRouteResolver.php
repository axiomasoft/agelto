<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Enums\Llm\DocumentScope;
use App\Enums\Llm\LlmPurpose;
use App\Models\Llm\LlmRoute;
use App\Models\Llm\Provider;
use App\Repositories\Llm\LlmRouteReadRepository;
use App\Services\Llm\Contracts\LlmProvider;
use App\Services\Llm\Dto\LlmOptions;
use App\Services\Llm\Dto\ResolvedRoute;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Резолвер матрицы роутинга `llm_routes` (ТЗ 04 §3.1): `(document_type, purpose)` → активная
 * строка с МИНИМАЛЬНЫМ `priority` (основной маршрут) + инстанс `LlmProvider` из `llm_providers`.
 * `candidatesFor()` отдаёт весь упорядоченный список — следующие по `priority` строки нужны
 * `ReaskPolicy` (P3.4) для эскалации при недоступности провайдера/провале валидации.
 */
final readonly class LlmRouteResolver
{
    // 8192 достаточно 2.5-линейке (нет/низкий `thinking`-бюджет); `gemini-3.x` тратит на reasoning
    // ДО ответа 5900-7900+ токенов сверх этого (боевой прогон P6.2 2026-07-28, модель сама называет
    // требуемый порог 12-14k/комфортный запас 23-26k — research/12-format-benchmark.md §5).
    private const int DEFAULT_MAX_TOKENS = 32768;

    public function __construct(
        private LlmRouteReadRepository $routes,
        private LlmProviderFactory $providerFactory,
    ) {}

    /**
     * Основной маршрут (минимальный `priority`) или исключение, если ни одной активной строки нет.
     */
    public function resolve(DocumentScope $documentType, LlmPurpose $purpose): ResolvedRoute
    {
        $primary = $this->candidatesFor($documentType, $purpose)->first();

        if ($primary === null) {
            throw new RuntimeException("Нет активного маршрута для document_type={$documentType->key()}, purpose={$purpose->value}.");
        }

        return $primary;
    }

    /**
     * Все активные маршруты для (document_type, purpose), `priority` ASC — первый элемент
     * основной, следующие — fallback/эскалация (ТЗ 04 §3.1).
     *
     * @return Collection<int, ResolvedRoute>
     */
    public function candidatesFor(DocumentScope $documentType, LlmPurpose $purpose): Collection
    {
        return $this->routes
            ->activeCandidatesFor($documentType, $purpose)
            ->map(fn (LlmRoute $route): ResolvedRoute => $this->toResolvedRoute($route, $purpose));
    }

    /**
     * Теневой маршрут (`shadow=true`, P9.4, ТЗ 04 §5) для (document_type, purpose) — первый по
     * `priority`, если активный теневой маршрут вообще заведён; `null` — shadow-режим для этой
     * пары выключен (обычное состояние, пока владелец не поднимет локальную LLM).
     */
    public function shadowRoute(DocumentScope $documentType, LlmPurpose $purpose): ?ResolvedRoute
    {
        $route = $this->routes->activeShadowRouteFor($documentType, $purpose);

        return $route !== null ? $this->toResolvedRoute($route, $purpose) : null;
    }

    private function toResolvedRoute(LlmRoute $route, LlmPurpose $purpose): ResolvedRoute
    {
        $provider = $route->provider;

        if (! $provider instanceof Provider) {
            throw new RuntimeException("Маршрут LLM #{$route->id} не содержит провайдера.");
        }

        // `(array) $route->options` НЕ работает на заполненной Collection (`AsCollection`-каст):
        // PHP-каст объекта в массив отдаёт его protected-свойства мангл-именами (`" * items"`),
        // а не данные `toArray()`/`all()` — до P2.9 не проявлялось, потому что `options` у ВСЕХ
        // строк сидера был `null` ((array) null === []); `AiRoutingSeeder` (D98/D99) впервые
        // заполняет его для extraction-маршрутов, и старый каст молча терял `max_tokens`/
        // `thinking_level`.
        $options = $this->options($route->options?->all() ?? [], 'route');
        $providerOptions = $this->options($provider->options, 'provider');

        $maxTokens = $this->positiveInt($options['max_tokens'] ?? self::DEFAULT_MAX_TOKENS, 'max_tokens');
        $this->assertWithinOutputCeiling($route->model, $maxTokens, $purpose);

        return new ResolvedRoute(
            provider: $this->providerFor($provider),
            options: new LlmOptions(
                provider: $provider->code,
                model: $route->model,
                temperature: $this->float($options['temperature'] ?? $purpose->defaultTemperature(), 'temperature'),
                maxTokens: $maxTokens,
                baseUrl: $provider->base_url,
                apiKeyEnv: $this->nullableString($providerOptions['api_key_env'] ?? null, 'api_key_env'),
                thinkingLevel: $this->nullableString($options['thinking_level'] ?? null, 'thinking_level'),
            ),
            route: $route,
        );
    }

    /**
     * Fail fast (D99): бюджет маршрута сверх ИЗВЕСТНОГО потолка вывода модели — операторская
     * опечатка, а не повод молча урезать (`config('ai.capabilities.<model>.max_output_tokens')`).
     * Потолок не подтверждён RAG для модели → ключа нет → проверка не выполняется (осознанная
     * граница D99, не пробел). Индекс по модели БЕЗ dot-notation (`LlmCallRecorder::costMicros()`,
     * `ExtractionPreflight` — тот же приём): имена моделей Gemini содержат точки
     * (`gemini-3.5-flash`), `config("ai.capabilities.{$model}...")` трактовал бы их как разделители
     * вложенности и НЕ нашёл бы запись.
     */
    private function assertWithinOutputCeiling(string $model, int $maxTokens, LlmPurpose $purpose): void
    {
        $capabilities = config('ai.capabilities', []);

        if (! is_array($capabilities)) {
            throw new RuntimeException('ai.capabilities должен быть массивом.');
        }

        $capability = $capabilities[$model] ?? null;
        $ceiling = is_array($capability) ? ($capability['max_output_tokens'] ?? null) : null;

        if ($ceiling === null) {
            return;
        }

        $ceiling = $this->positiveInt($ceiling, 'max_output_tokens');

        if ($maxTokens > $ceiling) {
            throw new RuntimeException(
                "Бюджет маршрута (purpose={$purpose->value}, model={$model}) запрошен {$maxTokens} ".
                "токенов — выше известного потолка вывода модели {$ceiling}.",
            );
        }
    }

    private function providerFor(Provider $provider): LlmProvider
    {
        return $this->providerFactory->forDriver($provider->driver);
    }

    /** @return array<string, mixed> */
    private function options(mixed $value, string $source): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw new RuntimeException("{$source} options должны быть объектом.");
        }

        foreach (array_keys($value) as $key) {
            if (! is_string($key)) {
                throw new RuntimeException("{$source} options содержит нестроковый ключ.");
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    private function positiveInt(mixed $value, string $key): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        throw new RuntimeException("{$key} должен быть положительным целым числом.");
    }

    private function float(mixed $value, string $key): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        throw new RuntimeException("{$key} должен быть числом.");
    }

    private function nullableString(mixed $value, string $key): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        throw new RuntimeException("{$key} должен быть строкой или null.");
    }
}
