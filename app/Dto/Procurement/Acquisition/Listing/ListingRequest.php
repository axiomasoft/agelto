<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition\Listing;

use App\Dto\Procurement\Platform\Diagnostics\CursorEnvelope;
use App\Dto\Procurement\Platform\Identity\CollectionGuard;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Enums\Procurement\Process\Core\AcquisitionKind;
use App\Services\Procurement\Platform\Contracts\ListingFilter;

final readonly class ListingRequest
{
    /** @param list<ContentHash> $observedPages */
    public function __construct(public string $registry, public ListingFilter $filter, public CursorEnvelope $cursor, public array $observedPages = [],
        public AcquisitionKind $kind = AcquisitionKind::Archive)
    {
        CollectionGuard::check($observedPages, ContentHash::class, 12);
    }
}
