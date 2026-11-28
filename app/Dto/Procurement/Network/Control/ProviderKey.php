<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network\Control;

/** Explicit configured provider identity; never inferred from a request host. */
final readonly class ProviderKey
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,95}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid outbound provider key.');
        }
    }
}
