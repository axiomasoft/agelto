<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition;

use App\Dto\Procurement\Platform\CollectionGuard;
use App\Dto\Procurement\Platform\CursorEnvelope;
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
