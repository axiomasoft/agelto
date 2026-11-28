<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition\Listing;

final readonly class ListingFetched implements ListingFetchResult
{
    public function __construct(public ListingPage $page) {}
}
