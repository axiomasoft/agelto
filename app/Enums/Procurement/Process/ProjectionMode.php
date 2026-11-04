<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process;

/** Режим projector: maintenance — gate bounded rebuild, live apply ждёт. */
enum ProjectionMode: string
{
    case Active = 'active';
    case Maintenance = 'maintenance';
}
