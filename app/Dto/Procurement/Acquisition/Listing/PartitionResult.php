<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition\Listing;

use App\Dto\Procurement\Platform\Diagnostics\CursorEnvelope;
use App\Dto\Procurement\Platform\Identity\CollectionGuard;
use App\Enums\Procurement\Platform\AssessmentReason;

final readonly class PartitionResult
{
    /** @param list<CursorEnvelope> $children */
    public function __construct(public array $children, public ?AssessmentReason $incompleteReason = null)
    {
        CollectionGuard::check($children, CursorEnvelope::class);
        if (($incompleteReason === null && count($children) < 2)
            || ($incompleteReason !== null && $children !== [])) {
            throw new \InvalidArgumentException('Invalid partition result.');
        }
    }
}
