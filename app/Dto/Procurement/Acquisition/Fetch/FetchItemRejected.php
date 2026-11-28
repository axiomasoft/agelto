<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition\Fetch;

use App\Dto\Procurement\Acquisition\Detail\DetailFetchResult;
use App\Dto\Procurement\Acquisition\Listing\ListingFetchResult;
use App\Dto\Procurement\Platform\Diagnostics\ResponseAssessment;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Enums\Procurement\Platform\FailureScope;
use App\Enums\Procurement\Process\Outcomes\ItemFailureReason;

final readonly class FetchItemRejected implements DetailFetchResult, ListingFetchResult
{
    public function __construct(public ItemFailureReason $reason, public ResponseAssessment $assessment, public ?string $inputEvidence = null)
    {
        if ($inputEvidence !== null && ($reason !== ItemFailureReason::InvalidInput || $assessment->failureScope !== FailureScope::Item
            || $inputEvidence === '' || strlen($inputEvidence) > 4096 || ContentHash::of($inputEvidence)->hex !== $assessment->bodyHash->hex)) {
            throw new \InvalidArgumentException('Invalid pre-send item evidence.');
        }
    }
}
