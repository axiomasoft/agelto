<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document;

use App\Dto\Procurement\Process\ContentHash;

/** Content-addressed upload for the synchronous stored-document import path. */
final readonly class StoredBlobUpload
{
    public function __construct(
        public string $disk,
        public ContentHash $hash,
        public int $sizeBytes,
    ) {}
}
