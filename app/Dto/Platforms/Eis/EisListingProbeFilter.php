<?php

declare(strict_types=1);

namespace App\Dto\Platforms\Eis;

use App\Dto\Procurement\Process\ContentHash;
use App\Services\Procurement\Platform\Contracts\ListingFilter;

/** One bounded unfiltered comparison page; never a persisted acquisition filter. */
final readonly class EisListingProbeFilter implements ListingFilter
{
    public string $sortBy;

    public function __construct(private EisListingFilter $scoped)
    {
        $this->sortBy = $scoped->sortBy;
    }

    /** @return array<string, string> */
    public function query(): array
    {
        $query = $this->scoped->query();
        unset($query['okpd2Ids'], $query['okpd2IdsWithNested']);

        return $query;
    }

    public function fingerprint(): ContentHash
    {
        return ContentHash::of('diagnostic-unfiltered:'.$this->scoped->fingerprint()->hex);
    }

    public function acceptsStage(string $stage): bool
    {
        return $this->scoped->acceptsStage($stage);
    }
}
