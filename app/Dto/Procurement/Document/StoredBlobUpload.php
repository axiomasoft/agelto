<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document;

use App\Dto\Procurement\Process\ContentHash;

/** Bounded upload for a synchronous stored-document import. */
final readonly class StoredBlobUpload
{
    public function __construct(
        public StorageReservationRequest $reservation,
        public ContentHash $hash,
        public int $sizeBytes,
    ) {}
}
