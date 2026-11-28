<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Inputs;

use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\ProcessValue;
use App\Enums\Procurement\Process\Core\StageCode;

final readonly class ListingInput implements WorkInput
{
    public function __construct(
        public ProcessUuid $crawlBlockId,
        public ContentHash $filterHash,
        public ContentHash $accessPolicyHash,
        public string $adapterVersion,
        public int $cursorVersion,
    ) {
        ProcessValue::text($adapterVersion);
        ProcessValue::positive($cursorVersion);
    }

    public function stage(): StageCode
    {
        return StageCode::Listing;
    }
}
