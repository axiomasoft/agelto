<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Crawler;

use App\Dto\Procurement\Crawler\ListingBatch;

final class InvalidListingResponse extends \RuntimeException
{
    public function __construct(public readonly ListingBatch $batch, public readonly string $reason)
    {
        parent::__construct("Listing response rejected: {$reason}.");
    }
}
