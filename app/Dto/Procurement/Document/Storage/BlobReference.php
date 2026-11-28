<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document\Storage;

use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;

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
