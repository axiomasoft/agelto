<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Enums\Procurement\SourceMethod as SourceMethodEnum;
use App\Models\Procurement\Crawler\Crawler;
use App\Services\Procurement\Contracts\SourceMethod;
use App\Services\Procurement\Exceptions\SourceMethodUnavailableException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;

/**
 * Резолвит реализацию `SourceMethod` по карте `config('crawler.source_methods')`
 * (enum-значение → FQCN), не по `match`-лестнице (`config-driven-abstraction`).
 */
final readonly class SourceMethodFactory
{
    public function __construct(
        private Container $container,
    ) {}

    public function make(SourceMethodEnum $method): SourceMethod
    {
        return $this->tryMake(method: $method) ?? throw SourceMethodUnavailableException::forUnconfiguredMethod(method: $method);
    }

    public function makeWithFallback(Crawler $crawler): SourceMethod
    {
        $primary = $this->tryMake(method: $crawler->source_method);

        if ($primary !== null && $primary->isAvailable()) {
            return $primary;
        }

        $fallback = $crawler->fallback_method;

        if ($fallback === null) {
            throw SourceMethodUnavailableException::forCrawler(method: $crawler->source_method, fallback: null);
        }

        $fallbackMethod = $this->tryMake(method: $fallback);

        if ($fallbackMethod === null || ! $fallbackMethod->isAvailable()) {
            throw SourceMethodUnavailableException::forCrawler(method: $crawler->source_method, fallback: $fallback);
        }

        Log::warning(
            message: 'Crawler source method switched to fallback.',
            context: ['crawler_id' => $crawler->id, 'primary' => $crawler->source_method->value, 'fallback' => $fallback->value],
        );

        return $fallbackMethod;
    }

    private function tryMake(SourceMethodEnum $method): ?SourceMethod
    {
        $class = config("crawler.source_methods.{$method->value}");

        if (! is_string($class)) {
            return null;
        }

        return $this->container->make(abstract: $class);
    }
}
