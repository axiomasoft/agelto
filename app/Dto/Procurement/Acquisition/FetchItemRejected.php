<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition;

use App\Dto\Procurement\Platform\ResponseAssessment;
use App\Dto\Procurement\Process\ContentHash;
use App\Enums\Procurement\Platform\FailureScope;
use App\Enums\Procurement\Process\ItemFailureReason;

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
