<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform\Contracts;

use App\Dto\Procurement\Acquisition\DetailFetchResult;
use App\Dto\Procurement\Acquisition\DetailRequest;
use App\Dto\Procurement\Process\ExecutionContext;

interface DetailPartsSource extends DetailSource
{
    /**
     * Ordered, bounded parts; first is the consistency control.
     *
     * @return non-empty-list<string>
     */
    public function parts(DetailRequest $request): array;

    /** A snapshot containing only assessments/data owned by this part. */
    public function fetchPart(DetailRequest $request, string $part, ExecutionContext $context): DetailFetchResult;
}
