<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Governance;

use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Process\Governance\InvariantKind;

/** Sanitized ledger identity and exact violated predicate, never a work payload. */
final readonly class InvariantViolation
{
    public function __construct(public InvariantKind $kind, public string $scopeKey)
    {
        $prefix = match ($kind) {
            InvariantKind::ReleasedMembers => 'group:',
            InvariantKind::LaneOutstanding, InvariantKind::LaneRunning => 'lane:',
            default => 'work:',
        };
        if (! str_starts_with($scopeKey, $prefix)) {
            throw new \InvalidArgumentException('Invariant subject does not match its predicate.');
        }
        new ProcessUuid(substr($scopeKey, strlen($prefix)));
    }

    public function fingerprint(): ContentHash
    {
        return ContentHash::of('process-invariant:v1:'.$this->kind->value.':'.$this->scopeKey);
    }
}
