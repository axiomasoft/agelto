<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document;

final readonly class StorageCleanupCandidate
{
    public function __construct(public StorageReservationRequest $reservation, public bool $writing) {}
}
