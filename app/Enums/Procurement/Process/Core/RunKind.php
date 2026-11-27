<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process\Core;

enum RunKind: string
{
    case Profile = 'profile';
    case Schedule = 'schedule';
    case Manual = 'manual';
    case Recovery = 'recovery';
    case Eval = 'eval';
}
