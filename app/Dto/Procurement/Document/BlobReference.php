<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document;

use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;

final readonly class BlobReference
{
    public function __construct(
        public ProcessUuid $id,
        public ProcessUuid $domainId,
        public ContentHash $hash,
        public string $disk,
        public string $objectKey,
        public int $sizeBytes,
    ) {}
}
