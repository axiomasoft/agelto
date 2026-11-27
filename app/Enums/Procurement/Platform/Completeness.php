<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Platform;

enum Completeness: string
{
    case Complete = 'complete';
    case CompleteEmpty = 'complete_empty';
    case Partial = 'partial';
    case Unavailable = 'unavailable';
    case NotApplicable = 'not_applicable';
}
