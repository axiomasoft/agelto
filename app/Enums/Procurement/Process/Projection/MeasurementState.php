<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process\Projection;

enum MeasurementState: string
{
    case Known = 'known';
    case Unknown = 'unknown';
    case NotApplicable = 'not_applicable';
}
