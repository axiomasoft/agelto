<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process\Core;

/** Учёт link в run summary: required входит в discovery progress, observes — только наблюдение. */
enum AccountingRole: string
{
    case Required = 'required';
    case Observes = 'observes';
}
