<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process\Governance;

enum ControlMode: string
{
    case Enabled = 'enabled';
    case Paused = 'paused';
}
