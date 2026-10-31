<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform;

use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;

final readonly class RegistryDefinitions
{
    /** @var array<string, RegistryDefinition> */
    public array $registries;

    /** @param array<array-key, mixed> $registries */
    public function __construct(array $registries)
    {
        if (! array_is_list($registries) || $registries === []) {
            throw new InvalidPlatformDefinition('Registry definitions require a nonempty list.');
        }
        $byCode = [];
        $types = [];
        foreach ($registries as $registry) {
            if (! $registry instanceof RegistryDefinition) {
                throw new InvalidPlatformDefinition('Expected typed RegistryDefinition.');
            }
            if (isset($byCode[$registry->code]) || isset($types[$registry->primaryIdentityType->value])) {
                throw new InvalidPlatformDefinition('Duplicate registry code or primary identity type: '.$registry->code);
            }
            $byCode[$registry->code] = $registry;
            $types[$registry->primaryIdentityType->value] = true;
        }
        ksort($byCode, SORT_STRING);
        $this->registries = $byCode;
    }

    public function get(string $code): RegistryDefinition
    {
        return $this->registries[$code] ?? throw new InvalidPlatformDefinition('Unknown registry: '.$code);
    }
}
