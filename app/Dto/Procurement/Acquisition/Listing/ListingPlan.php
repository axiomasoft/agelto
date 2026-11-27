<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition\Listing;

use App\Dto\Procurement\Platform\Identity\CollectionGuard;

final readonly class ListingPlan
{
    /** @param list<ListingWindow> $windows */
    public function __construct(public array $windows, public ?ListingWindow $continuation = null)
    {
        CollectionGuard::check($windows, ListingWindow::class, 400);
    }
}
