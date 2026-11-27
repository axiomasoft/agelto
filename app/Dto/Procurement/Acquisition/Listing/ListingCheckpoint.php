<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition\Listing;

use App\Dto\Procurement\Process\Outcomes\StageCheckpoint;
use App\Enums\Procurement\Process\Core\StageCode;

/** There is no partially accepted page. Recovery repeats its immutable request. */
final readonly class ListingCheckpoint implements StageCheckpoint
{
    public function stage(): StageCode
    {
        return StageCode::Listing;
    }
}
