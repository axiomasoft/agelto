<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Crawler;

/** An intentional, valid non-procurement row. Null from a mapper means malformed data. */
final readonly class SkippedListingRow
{
    public function __construct(public string $reason) {}
}
