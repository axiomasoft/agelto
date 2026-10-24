<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process;

enum HandlerEligibility: string
{
    case Healthy = 'healthy';
    case Held = 'held';
}
