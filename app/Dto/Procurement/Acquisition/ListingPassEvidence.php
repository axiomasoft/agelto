<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition;

use App\Dto\Procurement\Process\ContentHash;

/** Digest of full normalized observations, scoped to the immutable base partition (no pass ordinal). */
final readonly class ListingPassEvidence
{
    public function __construct(public ContentHash $partitionHash, public ContentHash $observationsHash, public bool $full, public int $ordinal)
    {
        if ($ordinal < 1 || $ordinal > 3) {
            throw new \InvalidArgumentException('Invalid stability pass.');
        }
    }
}
