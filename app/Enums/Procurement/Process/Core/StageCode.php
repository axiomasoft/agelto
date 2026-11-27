<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process\Core;

enum StageCode: string
{
    case Listing = 'listing';
    case Detail = 'detail';
    case StatusRefresh = 'status_refresh';
    case Download = 'download';
    case Prepare = 'prepare';
    case Screening = 'screening';
    case Extraction = 'extraction';
    case Publication = 'publication';
}
