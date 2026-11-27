<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Authority;

use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\ProcessValue;
use App\Enums\Procurement\Process\Governance\ControlMode;

final readonly class ControlSnapshot
{
    public function __construct(public ProcessUuid $id, public string $scopeKey, public ControlMode $mode, public int $version)
    {
        ProcessValue::text($scopeKey);
        ProcessValue::positive($version);
    }
}
