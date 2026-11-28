<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Authority;

use App\Dto\Procurement\Process\Primitives\ProcessValue;
use App\Enums\Procurement\Process\Governance\ControlChangeStatus;

/** Исход setMode: control_version после locked проверки expected version. */
final readonly class ControlChangeResult
{
    public function __construct(public ControlChangeStatus $status, public ?int $controlVersion)
    {
        if (($status === ControlChangeStatus::NotFound) !== ($controlVersion === null)) {
            throw new \InvalidArgumentException('Only a missing scope has no control version.');
        }
        if ($controlVersion !== null) {
            ProcessValue::positive($controlVersion);
        }
    }
}
