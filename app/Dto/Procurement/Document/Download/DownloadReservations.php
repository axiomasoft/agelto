<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document\Download;

use App\Dto\Procurement\Document\Storage\StorageReservationRequest;

final readonly class DownloadReservations
{
    public function __construct(public StorageReservationRequest $durable, public StorageReservationRequest $temporary) {}
}
