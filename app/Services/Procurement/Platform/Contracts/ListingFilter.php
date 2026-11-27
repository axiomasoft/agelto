<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform\Contracts;

use App\Dto\Procurement\Process\Primitives\ContentHash;

interface ListingFilter
{
    public function fingerprint(): ContentHash;
}
