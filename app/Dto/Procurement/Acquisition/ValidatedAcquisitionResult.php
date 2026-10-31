<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition;

use App\Dto\Procurement\Network\AdapterValidationReceipt;
use App\Dto\Procurement\Process\ValidatedStageResult;
use App\Enums\Procurement\Network\AdapterVerdict;
use App\Enums\Procurement\Process\StageCode;

final readonly class ValidatedAcquisitionResult implements ValidatedStageResult
{
    public function __construct(public ValidatedStageResult $result, public AdapterValidationReceipt $receipt)
    {
        if (! $result instanceof ListingResult && ! $result instanceof DetailResult && ! $result instanceof StatusResult) {
            throw new \InvalidArgumentException('Acquisition result required.');
        }
        if ($receipt->stale || ! in_array($receipt->verdict, [AdapterVerdict::Accepted, AdapterVerdict::Partial], true)) {
            throw new \InvalidArgumentException('Current validated acquisition evidence required.');
        }
    }

    public function stage(): StageCode
    {
        return $this->result->stage();
    }
}
