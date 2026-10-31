<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition;

use App\Dto\Procurement\Acquisition\DetailFetchResult;
use App\Dto\Procurement\Acquisition\FetchItemRejected;
use App\Dto\Procurement\Acquisition\ListingFetched;
use App\Dto\Procurement\Acquisition\ListingFetchResult;
use App\Dto\Procurement\Network\AdapterInspection;
use App\Dto\Procurement\Platform\ResponseAssessment;
use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\StageSucceeded;
use App\Enums\Procurement\Network\AdapterVerdict;
use App\Enums\Procurement\Platform\FailureScope;

/** Per-invocation diagnostic metadata; bodies and persistence stay with the adapter and ledger. */
final class AcquisitionAssessment
{
    private ?ResponseAssessment $rejected = null;

    /** @param ?\Closure(FetchItemRejected): FetchItemRejected $evidence */
    public function __construct(private readonly ?\Closure $evidence = null) {}

    public function rejection(FetchItemRejected $result): FetchItemRejected
    {
        return $this->evidence === null ? $result : ($this->evidence)($result);
    }

    public function observe(ListingFetchResult|DetailFetchResult $result): void
    {
        if ($result instanceof FetchItemRejected && $result->assessment->failureScope !== FailureScope::Item) {
            $this->rejected = $result->assessment;
        } elseif ($result instanceof ListingFetched && ! $result->page->assessment->usable()
            && $result->page->assessment->failureScope !== FailureScope::Item) {
            $this->rejected = $result->page->assessment;
        }
    }

    /** @return AdapterInspection<StageOutcome> */
    public function inspection(StageOutcome $result, ContentHash $contract): AdapterInspection
    {
        if ($this->rejected !== null) {
            $code = 'acquisition.'.$this->rejected->reason->value;

            return new AdapterInspection($result, AdapterVerdict::ContractFailure, $code, ContentHash::of($contract->hex.':'.$code));
        }

        return new AdapterInspection($result, $result instanceof StageSucceeded ? AdapterVerdict::Accepted : AdapterVerdict::Partial, 'acquisition.full_contract');
    }
}
