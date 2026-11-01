<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document;

final readonly class PrepareReservations
{
    public function __construct(public StorageReservationRequest $output, public StorageReservationRequest $spool, public StorageReservationRequest $workspace) {}
}
