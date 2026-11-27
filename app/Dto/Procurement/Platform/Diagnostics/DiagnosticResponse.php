<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform\Diagnostics;

use App\Dto\Procurement\Acquisition\Detail\DetailFetchResult;
use App\Dto\Procurement\Acquisition\Listing\ListingFetchResult;
use App\Dto\Procurement\Network\AdapterValidation\AdapterValidationReceipt;

final readonly class DiagnosticResponse
{
    public function __construct(public ListingFetchResult|DetailFetchResult $value,
        public ?AdapterValidationReceipt $receipt, public int $requestsUsed) {}
}
