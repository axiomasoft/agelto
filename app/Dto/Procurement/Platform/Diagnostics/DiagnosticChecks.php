<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform\Diagnostics;

use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;

final readonly class DiagnosticChecks
{
    /** @var array<string, string> */
    public array $implementations;

    /** @param array<array-key, mixed> $implementations */
    public function __construct(array $implementations)
    {
        foreach ($implementations as $code => $implementation) {
            if (! is_string($code) || preg_match('/^[a-z][a-z0-9_.:-]{0,95}$/D', $code) !== 1
                || ! is_string($implementation) || $implementation === '') {
                throw new InvalidPlatformDefinition('Invalid diagnostic declaration.');
            }
        }
        ksort($implementations, SORT_STRING);
        $this->implementations = $implementations;
    }
}
