<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Network;

/** Назначение network demand; probe проходит тот же FIFO без priority bypass (bp-rate-gcra). */
enum DemandPurpose: string
{
    case Ordinary = 'ordinary';
    case Probe = 'probe';
    case Diagnostic = 'diagnostic';
}
