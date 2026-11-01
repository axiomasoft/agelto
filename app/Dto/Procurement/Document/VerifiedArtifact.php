<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document;

use App\Dto\Procurement\Process\ContentHash;

/** Produced only after bounded immutable object read-back; no storage I/O under DB locks. */
final readonly class VerifiedArtifact
{
    public function __construct(
        public StorageReservationRequest $reservation,
        public ContentHash $hash,
        public int $sizeBytes,
    ) {
        if ($sizeBytes < 0 || $sizeBytes > $reservation->byteUpperBound) {
            throw new \InvalidArgumentException('Artifact exceeds its reserved byte ceiling.');
        }
    }
}
