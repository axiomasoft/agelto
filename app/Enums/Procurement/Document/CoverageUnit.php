<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Document;

enum CoverageUnit: string
{
    case Page = 'page';
    case Sheet = 'sheet';
    case Row = 'row';
}
