<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Ai;

enum ScreeningDisposition: string
{
    case Extract = 'extract';
    case Retain = 'retain';
    case Review = 'review';
    case Ignore = 'ignore';
}
