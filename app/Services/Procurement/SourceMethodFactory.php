<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Enums\Procurement\Crawler\SourceMethod as SourceMethodEnum;
use App\Exceptions\Procurement\Crawler\PlatformAdapterUnavailableException;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\Contracts\SourceMethod;
use App\Services\Procurement\Exceptions\SourceMethodUnavailableException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

/**
 * Резолвит реализацию `SourceMethod` по exact platform adapter выбранного реестра.
 */
final readonly class SourceMethodFactory
{
    public function __construct(
        private Container $container,
        private PlatformAdapterRegistry $adapters,
    ) {}

    public function make(SourceMethodEnum $method, Registry $registry): SourceMethod
    {
        return $this->tryMake(method: $method, registry: $registry)
            ?? throw SourceMethodUnavailableException::forUnconfiguredMethod(method: $method);
    }

    public function makeWithFallback(Crawler $crawler): SourceMethod
    {
        $registry = $crawler->registry;

        if (! $registry instanceof Registry) {
            throw PlatformAdapterUnavailableException::forPlatformRelation(registryCode: 'unknown');
        }

        $primary = $this->tryMake(method: $crawler->source_method, registry: $registry);

        if ($primary !== null && $primary->isAvailable()) {
            return $primary;
        }

        $fallback = $crawler->fallback_method;

        if ($fallback === null) {
            throw SourceMethodUnavailableException::forCrawler(method: $crawler->source_method, fallback: null);
        }

        $fallbackMethod = $this->tryMake(method: $fallback, registry: $registry);

        if ($fallbackMethod === null || ! $fallbackMethod->isAvailable()) {
            throw SourceMethodUnavailableException::forCrawler(method: $crawler->source_method, fallback: $fallback);
        }

        Log::warning(
            message: 'Crawler source method switched to fallback.',
            context: ['crawler_id' => $crawler->id, 'primary' => $crawler->source_method->value, 'fallback' => $fallback->value],
        );

        return $fallbackMethod;
    }

    private function tryMake(SourceMethodEnum $method, Registry $registry): ?SourceMethod
    {
        $class = $this->adapters->sourceMethod(registry: $registry, method: $method);

        return $this->container->make(abstract: $class);
    }
}
