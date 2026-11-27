<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process\Governance;

enum HandlerEligibility: string
{
    case Healthy = 'healthy';
    case Held = 'held';
}
