<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform;

use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;

final readonly class RegistryDefinition
{
    public function __construct(
        public string $code,
        public SourceIdentifierType $primaryIdentityType,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $code) !== 1) {
            throw new InvalidPlatformDefinition('Invalid registry code.');
        }
    }
}
