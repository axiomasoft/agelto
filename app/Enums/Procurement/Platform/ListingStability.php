<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Platform;

enum ListingStability: string
{
    case ObservedOpen = 'observed_open';
    case NeedsPass = 'needs_pass';
    case Stable = 'stable';
    case Incomplete = 'incomplete';
}
