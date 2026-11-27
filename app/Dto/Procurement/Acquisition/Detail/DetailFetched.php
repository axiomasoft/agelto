<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition\Detail;

final readonly class DetailFetched implements DetailFetchResult
{
    public function __construct(public ProcurementSnapshot $snapshot) {}
}
