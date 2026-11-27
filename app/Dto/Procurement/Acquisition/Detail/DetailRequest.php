<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition\Detail;

use App\Dto\Procurement\Platform\Diagnostics\CursorEnvelope;
use App\Dto\Procurement\Platform\Identity\SourceIdentity;

final readonly class DetailRequest
{
    public function __construct(public string $registry, public SourceIdentity $identity, public ?CursorEnvelope $locator = null,
        public bool $statusOnly = false) {}
}
