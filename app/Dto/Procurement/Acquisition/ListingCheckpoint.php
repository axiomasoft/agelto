<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition;

use App\Dto\Procurement\Process\StageCheckpoint;
use App\Enums\Procurement\Process\StageCode;

/** There is no partially accepted page. Recovery repeats its immutable request. */
final readonly class ListingCheckpoint implements StageCheckpoint
{
    public function stage(): StageCode
    {
        return StageCode::Listing;
    }
}
