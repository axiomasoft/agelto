<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform;

use App\Dto\Procurement\Acquisition\DetailFetchResult;
use App\Dto\Procurement\Acquisition\ListingFetchResult;
use App\Dto\Procurement\Network\AdapterValidationReceipt;

final readonly class DiagnosticResponse
{
    public function __construct(public ListingFetchResult|DetailFetchResult $value,
        public ?AdapterValidationReceipt $receipt, public int $requestsUsed) {}
}
