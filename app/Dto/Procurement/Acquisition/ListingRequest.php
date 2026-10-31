<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition;

use App\Dto\Procurement\Platform\CollectionGuard;
use App\Dto\Procurement\Platform\CursorEnvelope;
use App\Dto\Procurement\Process\ContentHash;
use App\Enums\Procurement\Process\AcquisitionKind;
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
