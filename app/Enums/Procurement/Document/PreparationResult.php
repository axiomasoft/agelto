<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Document;

enum PreparationResult: string
{
    case ReadyForScreening = 'ready_for_screening';
    case ExpandedContainer = 'expanded_container';
    case Unprocessable = 'unprocessable';
}
