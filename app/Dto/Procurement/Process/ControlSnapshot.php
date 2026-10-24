<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use App\Enums\Procurement\Process\ControlMode;

final readonly class ControlSnapshot
{
    public function __construct(public ProcessUuid $id, public string $scopeKey, public ControlMode $mode, public int $version)
    {
        ProcessValue::text($scopeKey);
        ProcessValue::positive($version);
    }
}
