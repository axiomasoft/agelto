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
use Illuminate\Database\Eloquent\Model;
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
            if (! is_string($platformCode) || $platformCode === '' || ! is_array($definition)) {
                throw new PlatformAdapterConfigurationException('Карта crawler.platforms должна быть индексирована непустыми platform code.');
            }

            $sourceMethods = self::stringKeyedMap(
                value: $definition['source_methods'] ?? [],
                field: "source_methods площадки «{$platformCode}»",
            );
            $registryFilters = self::stringKeyedMap(
                value: $definition['registry_filters'] ?? [],
                field: "registry_filters площадки «{$platformCode}»",
            );

            $definitions[$platformCode] = new PlatformAdapterDefinition(
                platformCode: $platformCode,
                sourceMethods: $sourceMethods,
                registryFilters: $registryFilters,
            );
        }

        $this->definitions = $definitions;
    }

    public function forRegistry(Registry $registry): PlatformAdapterDefinition
    {
        $platform = $registry->relationLoaded('platform')
            ? $registry->getRelation('platform')
            : $registry->platform()->first();

        $registryCode = self::stringAttribute(model: $registry, attribute: 'code');

        if (! $platform instanceof Platform) {
            throw PlatformAdapterUnavailableException::forPlatformRelation(registryCode: $registryCode);
        }

        $platformCode = self::stringAttribute(model: $platform, attribute: 'code');
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
        return $this->forRegistry(registry: $registry)->filterClass(registryCode: self::stringAttribute(model: $registry, attribute: 'code'));
    }

    /** @return class-string<CrawlerPlatformForm> */
    public function formClass(Registry $registry): string
    {
        return $this->forRegistry(registry: $registry)->formClass(registryCode: self::stringAttribute(model: $registry, attribute: 'code'));
    }

    public function scopeMessage(Registry $registry): string
    {
        return $this->forRegistry(registry: $registry)->scopeMessage(registryCode: self::stringAttribute(model: $registry, attribute: 'code'));
    }

    /** @return array<string, mixed> */
    private static function stringKeyedMap(mixed $value, string $field): array
    {
        if (! is_array($value)) {
            throw new PlatformAdapterConfigurationException("{$field} должен быть картой.");
        }

        $result = [];

        foreach ($value as $key => $entry) {
            if (! is_string($key)) {
                throw new PlatformAdapterConfigurationException("{$field} должен быть картой со строковыми ключами.");
            }

            $result[$key] = $entry;
        }

        return $result;
    }

    private static function stringAttribute(Model $model, string $attribute): string
    {
        $value = $model->getAttribute($attribute);

        if (! is_string($value) || $value === '') {
            throw new PlatformAdapterConfigurationException("Атрибут {$attribute} платформы должен быть непустой строкой.");
        }

        return $value;
    }
}
