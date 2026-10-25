<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use App\Enums\Procurement\Process\ScopeKind;

/**
 * Immutable topology of one control authority row: purpose-qualified key and parent key.
 * Только system — корень; parent/key после создания не меняются (execution#s08).
 */
final readonly class ScopeDefinition
{
    public function __construct(public ScopeKind $kind, public string $key, public ?string $parentKey)
    {
        ProcessValue::text($key);
        if ($parentKey !== null) {
            ProcessValue::text($parentKey);
        }
        if (($kind === ScopeKind::System) !== ($parentKey === null) || $parentKey === $key) {
            throw new \InvalidArgumentException('Only the system scope is a root and a scope cannot be its own parent.');
        }
    }
}
