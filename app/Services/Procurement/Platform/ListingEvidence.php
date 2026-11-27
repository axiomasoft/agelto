<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform;

use App\Dto\Procurement\Acquisition\Listing\ListingPage;
use App\Dto\Procurement\Acquisition\Listing\ListingPassEvidence;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Enums\Procurement\Platform\ListingStability;

/** Pure evidence evaluation. Acquisition owns durable membership, watermarks and successor accounting. */
final readonly class ListingEvidence
{
    public function stability(ListingPassEvidence $current, ?ListingPassEvidence $previous, bool $closed): ListingStability
    {
        if (! $current->full) {
            return ListingStability::Incomplete;
        }
        if (! $closed) {
            return ListingStability::ObservedOpen;
        }
        if ($previous !== null && $previous->full && $previous->ordinal + 1 === $current->ordinal
            && $previous->partitionHash->hex === $current->partitionHash->hex
            && $previous->observationsHash->hex === $current->observationsHash->hex) {
            return ListingStability::Stable;
        }

        return $current->ordinal >= 3 ? ListingStability::Incomplete : ListingStability::NeedsPass;
    }

    /** @param list<ContentHash> $seen */
    public function repeated(ListingPage $page, array $seen): bool
    {
        if (count($seen) > 12) {
            throw new \InvalidArgumentException('Unbounded page digest history.');
        }
        $digest = $page->summaries->digest(false)->hex;
        foreach ($seen as $hash) {
            if ($hash->hex === $digest) {
                return true;
            }
        }

        return false;
    }
}
