<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition\Listing;

use App\Dto\Procurement\Process\Outcomes\ValidatedStageResult;
use App\Enums\Procurement\Platform\AssessmentReason;
use App\Enums\Procurement\Process\Core\StageCode;

final readonly class ListingResult implements ValidatedStageResult
{
    public function __construct(public ListingTask $task, public ListingPage $page, public ?PartitionResult $split = null,
        public \DateTimeImmutable $observedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        public ?string $activeRulesHash = null)
    {
        $overflow = in_array($page->assessment->reason, [AssessmentReason::Overflow, AssessmentReason::RepeatedPage], true);
        if ((! $page->assessment->usable() && ! $overflow) || ($overflow !== ($split !== null))) {
            throw new \InvalidArgumentException('Unvalidated listing result.');
        }
    }

    public function stage(): StageCode
    {
        return StageCode::Listing;
    }
}
