<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Exceptions\Procurement\UnknownProviderDriver;
use App\Services\Llm\Contracts\LlmProvider;
use App\Services\Llm\Providers\OpenAiCompatibleProvider;
use App\Services\Llm\Providers\PrismGeminiProvider;
use Illuminate\Contracts\Container\Container;

/**
 * Единственный `match` по строке драйвера `llm_providers.driver` в проекте
 * (`root/service-layer-map.md` §8) — до этого класса `LlmRouteResolver::providerFor()` и
 * `CachesCommand::providerForDriver()` держали ДВЕ независимые копии одного и того же
 * ветвления; появление второго провайдера теперь меняется в ОДНОМ месте.
 */
final readonly class LlmProviderFactory
{
    public function __construct(private Container $container) {}

    public function forDriver(string $driver): LlmProvider
    {
        return match ($driver) {
            'gemini' => $this->container->make(PrismGeminiProvider::class),
            // OpenAI-совместимый транспорт (vLLM/Ollama, P9.4, ТЗ 04 §7) — stateless: base_url/
            // api_key приходят через `LlmOptions`, не через конструктор.
            'openai_compatible' => $this->container->make(OpenAiCompatibleProvider::class),
            default => throw new UnknownProviderDriver($driver),
        };
    }
}
