<?php

declare(strict_types=1);

namespace Tests\Support\Platforms\JsonProcurement;

use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Services\Procurement\Platform\Contracts\ListingFilter;

final readonly class JsonListingFilter implements ListingFilter
{
    public function __construct(public string $collection)
    {
        if (! preg_match('/^[a-z][a-z0-9-]{0,50}$/D', $collection)) {
            throw new \InvalidArgumentException('Invalid JSON collection.');
        }
    }

    public function fingerprint(): ContentHash
    {
        return ContentHash::of($this->collection);
    }
}
