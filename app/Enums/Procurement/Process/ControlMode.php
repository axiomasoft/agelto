<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process;

enum ControlMode: string
{
    case Enabled = 'enabled';
    case Paused = 'paused';
}
