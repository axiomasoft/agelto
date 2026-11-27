<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform\Diagnostics;

use App\Dto\Procurement\Acquisition\Detail\DetailRequest;
use App\Dto\Procurement\Acquisition\Listing\ListingRequest;

/** The exact trusted request supplies identity, registry and pinned filter/cursor expectations. */
final readonly class ExpectedResource
{
    public function __construct(public ListingRequest|DetailRequest $request) {}
}
