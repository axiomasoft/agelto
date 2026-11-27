<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform;

use App\Dto\Procurement\Platform\Definition\PlatformDefinition;
use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;
use App\Services\Procurement\Platform\Contracts\PlatformDefinitionProvider;
use Illuminate\Contracts\Config\Repository;
use ReflectionClass;
use ReflectionNamedType;

final readonly class PlatformRegistry
{
    /** @var array<string, PlatformDefinition> */
    private array $definitions;

    public function __construct(Repository $config)
    {
        $registrations = $config->get('platforms.definitions');
        if (! is_array($registrations)) {
            throw new InvalidPlatformDefinition('platforms.definitions: expected code => provider class.');
        }
        $validator = new PlatformBootValidator;
        $definitions = [];
        $providers = [];
        foreach ($registrations as $key => $class) {
            if (! is_string($key) || ! is_string($class)) {
                throw new InvalidPlatformDefinition('platforms.definitions: expected code => provider class.');
            }
            new PlatformCode($key);
            $path = 'platforms.definitions.'.$key;
            $validator->implementation($class, PlatformDefinitionProvider::class, $path);
            if (isset($providers[$class])) {
                throw new InvalidPlatformDefinition($path.': duplicate definition provider.');
            }
            $providers[$class] = true;
            // Provider construction accepts only config. Never resolve an arbitrary DI graph at boot.
            $reflection = new ReflectionClass($class);
            $parameters = $reflection->getConstructor()?->getParameters() ?? [];
            if (count($parameters) > 1 || (count($parameters) === 1
                && (! ($parameters[0]->getType() instanceof ReflectionNamedType)
                    || $parameters[0]->getType()->getName() !== Repository::class
                    || $parameters[0]->isVariadic() || $parameters[0]->isPassedByReference()))) {
                throw new InvalidPlatformDefinition($path.': provider constructor may depend only on config Repository.');
            }
            $provider = $parameters === [] ? new $class : new $class($config);
            if (! $provider instanceof PlatformDefinitionProvider) {
                throw new InvalidPlatformDefinition($path.': invalid provider.');
            }
            $definition = $provider->definition();
            $validator->validate($definition, $key, $config);
            $definitions[$key] = $definition;
        }
        ksort($definitions, SORT_STRING);
        $this->definitions = $definitions;
    }

    public function get(PlatformCode $code): PlatformDefinition
    {
        return $this->definitions[$code->value] ?? throw new InvalidPlatformDefinition('Unregistered platform: '.$code->value);
    }

    /** @return list<PlatformDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }
}
