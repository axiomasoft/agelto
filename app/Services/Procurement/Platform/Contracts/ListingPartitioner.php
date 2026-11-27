<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform\Contracts;

use App\Dto\Procurement\Acquisition\Listing\PartitionResult;
use App\Dto\Procurement\Platform\Diagnostics\CursorEnvelope;

interface ListingPartitioner
{
    public function split(CursorEnvelope $cursor, int $allocatedPartitions): PartitionResult;
}
