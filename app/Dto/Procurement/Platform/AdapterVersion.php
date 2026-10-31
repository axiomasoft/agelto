<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform;

use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;

final readonly class AdapterVersion
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/D', $value) !== 1) {
            throw new InvalidPlatformDefinition('Invalid AdapterVersion.');
        }
    }
}
