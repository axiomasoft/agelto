<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Contracts\Procurement\Crawler\CrawlerPlatformForm;
use App\Dto\Procurement\Crawler\PlatformAdapterDefinition;
use App\Enums\Procurement\Crawler\SourceMethod;
use App\Exceptions\Procurement\Crawler\PlatformAdapterConfigurationException;
use App\Exceptions\Procurement\Crawler\PlatformAdapterUnavailableException;
use App\Models\Procurement\Platform\Platform;
use App\Models\Procurement\Platform\Registry;
use App\Services\Procurement\Contracts\SourceMethod as SourceMethodContract;
use Spatie\LaravelData\Data;

final readonly class PlatformAdapterRegistry
{
    /** @var array<string, PlatformAdapterDefinition> */
    private array $definitions;

    /**
     * @param  array<string, array{source_methods?: array<string, class-string<SourceMethodContract>>, registry_filters?: array<string, array{filter: class-string<Data>, form: class-string<CrawlerPlatformForm>, scope_message?: string}>}>|null  $platforms
     */
    public function __construct(?array $platforms = null)
    {
        $platforms ??= config('crawler.platforms');

        if (! is_array($platforms)) {
            throw new PlatformAdapterConfigurationException("config('crawler.platforms') должен содержать карту платформ.");
        }

        $definitions = [];

        foreach ($platforms as $platformCode => $definition) {
            if (! is_string($platformCode) || ! is_array($definition)) {
                throw new PlatformAdapterConfigurationException('Карта crawler.platforms должна быть индексирована непустыми platform code.');
            }

            $definitions[$platformCode] = new PlatformAdapterDefinition(
                platformCode: $platformCode,
                sourceMethods: $definition['source_methods'] ?? [],
                registryFilters: $definition['registry_filters'] ?? [],
            );
        }

        $this->definitions = $definitions;
    }

    public function forRegistry(Registry $registry): PlatformAdapterDefinition
    {
        $platform = $registry->relationLoaded('platform')
            ? $registry->getRelation('platform')
            : $registry->platform()->first();

        $registryCode = (string) $registry->getAttribute('code');

        if (! $platform instanceof Platform) {
            throw PlatformAdapterUnavailableException::forPlatformRelation(registryCode: $registryCode);
        }

        $platformCode = (string) $platform->getAttribute('code');
        $definition = $this->definitions[$platformCode] ?? null;

        if (! $definition instanceof PlatformAdapterDefinition) {
            throw PlatformAdapterUnavailableException::forPlatform(platformCode: $platformCode);
        }

        return $definition;
    }

    /** @return class-string<SourceMethodContract> */
    public function sourceMethod(Registry $registry, SourceMethod $method): string
    {
        return $this->forRegistry(registry: $registry)->sourceMethod(method: $method);
    }

    /** @return class-string<Data> */
    public function filterClass(Registry $registry): string
    {
        return $this->forRegistry(registry: $registry)->filterClass(registryCode: (string) $registry->getAttribute('code'));
    }

    /** @return class-string<CrawlerPlatformForm> */
    public function formClass(Registry $registry): string
    {
        return $this->forRegistry(registry: $registry)->formClass(registryCode: (string) $registry->getAttribute('code'));
    }

    public function scopeMessage(Registry $registry): string
    {
        return $this->forRegistry(registry: $registry)->scopeMessage(registryCode: (string) $registry->getAttribute('code'));
    }
}
