<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Enums\Procurement\LlmPurpose;
use App\Models\Procurement\Ai\AiRoute;
use App\Models\Procurement\Ai\Provider;
use App\Repositories\Procurement\AiRouteReadRepository;
use App\Services\Llm\Contracts\LlmProvider;
use App\Services\Llm\Dto\LlmOptions;
use App\Services\Llm\Dto\ResolvedRoute;
use App\Services\Llm\Providers\OpenAiCompatibleProvider;
use App\Services\Llm\Providers\PrismGeminiProvider;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Резолвер матрицы роутинга `prc_ai_routes` (ТЗ 04 §3.1): `(document_type, purpose)` → активная
 * строка с МИНИМАЛЬНЫМ `priority` (основной маршрут) + инстанс `LlmProvider` из `prc_ai_providers`.
 * `candidatesFor()` отдаёт весь упорядоченный список — следующие по `priority` строки нужны
 * `ReaskPolicy` (P3.4) для эскалации при недоступности провайдера/провале валидации.
 */
final readonly class AiRouteResolver
{
    private const array DEFAULT_TEMPERATURE_BY_PURPOSE = [
        'screening' => 0.0,
        'extract_passport' => 0.0,
        'extract_rows' => 0.0,
        'extract_totals' => 0.0,
    ];

    // 8192 достаточно 2.5-линейке (нет/низкий `thinking`-бюджет); `gemini-3.x` тратит на reasoning
    // ДО ответа 5900-7900+ токенов сверх этого (боевой прогон P6.2 2026-07-28, модель сама называет
    // требуемый порог 12-14k/комфортный запас 23-26k — research/12-format-benchmark.md §5).
    private const int DEFAULT_MAX_TOKENS = 32768;

    public function __construct(
        private AiRouteReadRepository $routes,
        private Container $container,
    ) {}

    /**
     * Основной маршрут (минимальный `priority`) или исключение, если ни одной активной строки нет.
     */
    public function resolve(string $documentType, LlmPurpose $purpose): ResolvedRoute
    {
        $primary = $this->candidatesFor($documentType, $purpose)->first();

        if ($primary === null) {
            throw new RuntimeException("Нет активного маршрута для document_type={$documentType}, purpose={$purpose->value}.");
        }

        return $primary;
    }

    /**
     * Все активные маршруты для (document_type, purpose), `priority` ASC — первый элемент
     * основной, следующие — fallback/эскалация (ТЗ 04 §3.1).
     *
     * @return Collection<int, ResolvedRoute>
     */
    public function candidatesFor(string $documentType, LlmPurpose $purpose): Collection
    {
        return $this->routes
            ->activeCandidatesFor($documentType, $purpose)
            ->map(fn (AiRoute $route): ResolvedRoute => $this->toResolvedRoute($route, $purpose));
    }

    /**
     * Теневой маршрут (`shadow=true`, P9.4, ТЗ 04 §5) для (document_type, purpose) — первый по
     * `priority`, если активный теневой маршрут вообще заведён; `null` — shadow-режим для этой
     * пары выключен (обычное состояние, пока владелец не поднимет локальную LLM).
     */
    public function shadowRoute(string $documentType, LlmPurpose $purpose): ?ResolvedRoute
    {
        $route = $this->routes->activeShadowRouteFor($documentType, $purpose);

        return $route !== null ? $this->toResolvedRoute($route, $purpose) : null;
    }

    private function toResolvedRoute(AiRoute $route, LlmPurpose $purpose): ResolvedRoute
    {
        $provider = $route->provider;

        $options = (array) $route->options;
        $providerOptions = (array) $provider->options;

        return new ResolvedRoute(
            provider: $this->providerFor($provider),
            options: new LlmOptions(
                provider: $provider->code,
                model: $route->model,
                temperature: (float) ($options['temperature'] ?? self::DEFAULT_TEMPERATURE_BY_PURPOSE[$purpose->value] ?? 0.0),
                maxTokens: (int) ($options['max_tokens'] ?? self::DEFAULT_MAX_TOKENS),
                baseUrl: $provider->base_url,
                apiKeyEnv: $providerOptions['api_key_env'] ?? null,
            ),
            route: $route,
        );
    }

    private function providerFor(Provider $provider): LlmProvider
    {
        return match ($provider->driver) {
            'gemini' => $this->container->make(PrismGeminiProvider::class),
            // OpenAI-совместимый транспорт (vLLM/Ollama, P9.4, ТЗ 04 §7) — stateless: base_url/
            // api_key приходят через `LlmOptions` (см. докблок), не через конструктор, чтобы
            // `app()->instance(OpenAiCompatibleProvider::class, $fake)` продолжал работать в тестах.
            'openai_compatible' => $this->container->make(OpenAiCompatibleProvider::class),
            default => throw new RuntimeException("Неизвестный драйвер провайдера: {$provider->driver}."),
        };
    }
}
