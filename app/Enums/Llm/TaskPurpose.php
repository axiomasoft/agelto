<?php

declare(strict_types=1);

namespace App\Enums\Llm;

enum TaskPurpose: string
{
    case Screening = 'screening';
    case Discovery = 'discovery';
    case Passport = 'passport';
    case Rows = 'rows';
    case Totals = 'totals';
    case Repair = 'repair';
}
