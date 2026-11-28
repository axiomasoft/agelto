<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Network;

/** Health адаптера: новая версия начинается validating (только contract canary). */
enum AdapterHealthState: string
{
    case Healthy = 'healthy';
    case Suspect = 'suspect';
    case Held = 'held';
    case Validating = 'validating';
}
