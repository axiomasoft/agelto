<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform;

use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;

final readonly class UnsupportedCapability
{
    public function __construct(public string $reason)
    {
        if ($reason === '' || strlen($reason) > 200 || preg_match('/[\x00-\x1f\x7f]/', $reason)) {
            throw new InvalidPlatformDefinition('Unsupported capability needs a bounded reason.');
        }
    }
}
