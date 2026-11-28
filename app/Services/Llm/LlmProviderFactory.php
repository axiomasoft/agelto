<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Exceptions\Llm\UnknownProviderDriver;
use App\Repositories\Llm\LlmProviderReadRepository;
use App\Services\Llm\Contracts\LlmProvider;
use App\Services\Llm\Providers\CatalogOnlyProvider;
use Illuminate\Contracts\Container\Container;

/**
 * Catalog origins and legacy route metadata. Execution belongs to the durable provider owner.
 */
final readonly class LlmProviderFactory
{
    public function __construct(private Container $container, private LlmProviderReadRepository $configured) {}

    /** @return array<string, list<string>> Invoked only by explicit catalog synchronization. */
    public function networkOrigins(): array
    {
        $origins = [];
        foreach ($this->configured->active() as $configuration) {
            $origins[$configuration->code] = [match ($configuration->driver) {
                'gemini' => $configuration->base_url ?? 'https://generativelanguage.googleapis.com',
                'openai_compatible' => $configuration->base_url !== null && $configuration->base_url !== ''
                    ? $configuration->base_url : throw new \RuntimeException('OpenAI-compatible provider has no configured base URL.'),
                default => throw new UnknownProviderDriver($configuration->driver),
            }];
        }

        return $origins;
    }

    public function forDriver(string $driver): LlmProvider
    {
        return match ($driver) {
            'gemini', 'openai_compatible' => $this->container->make(CatalogOnlyProvider::class),
            default => throw new UnknownProviderDriver($driver),
        };
    }
}
