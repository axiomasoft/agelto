<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Crawler;

use App\Contracts\Procurement\Crawler\CrawlerPlatformForm;
use App\Enums\Procurement\Crawler\SourceMethod;
use App\Exceptions\Procurement\Crawler\PlatformAdapterConfigurationException;
use App\Exceptions\Procurement\Crawler\PlatformAdapterUnavailableException;
use App\Services\Procurement\Contracts\SourceMethod as SourceMethodContract;
use Spatie\LaravelData\Data;

final readonly class PlatformAdapterDefinition
{
    /** @var array<string, class-string<SourceMethodContract>> */
    private array $sourceMethods;

    /** @var array<string, array{filter: class-string<Data>, form: class-string<CrawlerPlatformForm>, scope_message?: string}> */
    private array $registryFilters;

    /**
     * @param  array<string, mixed>  $sourceMethods
     * @param  array<string, mixed>  $capabilities
     * @param  array<string, mixed>  $registryFilters
     */
    public function __construct(
        public string $platformCode,
        array $sourceMethods,
        array $registryFilters,
        public array $capabilities = [],
    ) {
        if ($platformCode === '') {
            throw new PlatformAdapterConfigurationException('Код platform adapter definition не может быть пустым.');
        }

        $normalizedSourceMethods = [];

        foreach ($sourceMethods as $method => $class) {
            if (SourceMethod::tryFrom($method) === null || ! is_string($class) || ! is_a($class, SourceMethodContract::class, allow_string: true)) {
                throw new PlatformAdapterConfigurationException("Площадка «{$platformCode}» содержит невалидный source method «{$method}».");
            }

            /** @var class-string<SourceMethodContract> $class */
            $normalizedSourceMethods[$method] = $class;
        }

        $normalizedRegistryFilters = [];

        foreach ($registryFilters as $registryCode => $definition) {
            if (! is_array($definition)) {
                throw new PlatformAdapterConfigurationException("Площадка «{$platformCode}» содержит невалидное filter definition для реестра «{$registryCode}».");
            }

            $filter = $definition['filter'] ?? null;
            $form = $definition['form'] ?? null;
            $scopeMessage = $definition['scope_message'] ?? null;

            if ($registryCode === '' || ! is_string($filter) || ! is_a($filter, Data::class, allow_string: true)) {
                throw new PlatformAdapterConfigurationException("Площадка «{$platformCode}» содержит невалидный filter для реестра «{$registryCode}».");
            }

            if (! is_string($form) || ! is_a($form, CrawlerPlatformForm::class, allow_string: true)) {
                throw new PlatformAdapterConfigurationException("Площадка «{$platformCode}» содержит невалидную form definition для реестра «{$registryCode}».");
            }

            if ($scopeMessage !== null && ! is_string($scopeMessage)) {
                throw new PlatformAdapterConfigurationException("Площадка «{$platformCode}» содержит невалидный scope message для реестра «{$registryCode}».");
            }

            /** @var class-string<Data> $filter */
            /** @var class-string<CrawlerPlatformForm> $form */
            $normalizedRegistryFilters[$registryCode] = $scopeMessage === null
                ? ['filter' => $filter, 'form' => $form]
                : ['filter' => $filter, 'form' => $form, 'scope_message' => $scopeMessage];
        }

        $this->sourceMethods = $normalizedSourceMethods;
        $this->registryFilters = $normalizedRegistryFilters;
    }

    /** @return class-string<SourceMethodContract> */
    public function sourceMethod(SourceMethod $method): string
    {
        $class = $this->sourceMethods[$method->value] ?? null;

        if (! is_string($class)) {
            throw PlatformAdapterUnavailableException::forSourceMethod(platformCode: $this->platformCode, method: $method);
        }

        return $class;
    }

    /** @return class-string<Data> */
    public function filterClass(string $registryCode): string
    {
        $class = $this->registryFilters[$registryCode]['filter'] ?? null;

        if (! is_string($class)) {
            throw PlatformAdapterUnavailableException::forRegistry(platformCode: $this->platformCode, registryCode: $registryCode);
        }

        return $class;
    }

    /** @return class-string<CrawlerPlatformForm> */
    public function formClass(string $registryCode): string
    {
        $class = $this->registryFilters[$registryCode]['form'] ?? null;

        if (! is_string($class)) {
            throw PlatformAdapterUnavailableException::forRegistry(platformCode: $this->platformCode, registryCode: $registryCode);
        }

        return $class;
    }

    public function scopeMessage(string $registryCode): string
    {
        $definition = $this->registryFilters[$registryCode] ?? null;

        if (! is_array($definition)) {
            throw PlatformAdapterUnavailableException::forRegistry(platformCode: $this->platformCode, registryCode: $registryCode);
        }

        return $definition['scope_message'] ?? 'заполните обязательный предметный скоуп фильтра.';
    }
}
