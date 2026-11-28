<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform\Identity;

use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;

final readonly class SourceIdentifierValue
{
    public function __construct(public string $value)
    {
        if (! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")
            || mb_strlen($value, 'UTF-8') < 1 || mb_strlen($value, 'UTF-8') > 200) {
            throw new InvalidPlatformDefinition('Source identity value must be valid UTF-8, 1..200 characters, without NUL.');
        }
    }
}
