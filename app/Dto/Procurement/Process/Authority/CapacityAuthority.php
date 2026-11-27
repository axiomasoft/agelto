<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Authority;

use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Process\Governance\CapacityKind;

final readonly class CapacityAuthority
{
    public function __construct(public CapacityKind $kind, public ProcessUuid $id) {}

    public function key(): string
    {
        return $this->kind->value.':'.$this->id->value;
    }
}
