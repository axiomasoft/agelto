<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform\Contracts;

use App\Dto\Procurement\Platform\CursorEnvelope;

interface ListingFilterCodec
{
    public function restoreFilter(CursorEnvelope $snapshot): ListingFilter;
}
