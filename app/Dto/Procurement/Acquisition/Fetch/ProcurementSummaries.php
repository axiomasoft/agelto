<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition\Fetch;

use App\Dto\Procurement\Platform\Identity\CollectionGuard;
use App\Dto\Procurement\Process\Primitives\ContentHash;

/** Bounded page membership; duplicate observations retain cardinality in digests. */
final readonly class ProcurementSummaries
{
    /** @param list<ProcurementSummary> $items */
    public function __construct(public array $items)
    {
        CollectionGuard::check($items, ProcurementSummary::class);
    }

    public function digest(bool $content = true): ContentHash
    {
        $rows = [];
        foreach ($this->items as $item) {
            $rows[] = json_encode([$item->identity->toArray(), ...($content ? [$item->sourceRevision, $item->fingerprint->hex] : [])], JSON_THROW_ON_ERROR);
        }
        sort($rows, SORT_STRING);

        return new ContentHash(hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)));
    }
}
