<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform\Identity;

use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;

final readonly class PlatformCode
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $value) !== 1) {
            throw new InvalidPlatformDefinition('Invalid PlatformCode.');
        }
    }
}
