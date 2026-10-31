<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition;

use App\Dto\Procurement\Platform\CursorEnvelope;

final readonly class ListingWindow
{
    public function __construct(public CursorEnvelope $cursor, public string $start, public string $end, public string $closesAt) {}
}
