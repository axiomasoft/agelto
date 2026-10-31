<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform\Contracts;

use App\Dto\Procurement\Acquisition\DetailFetchResult;
use App\Dto\Procurement\Acquisition\DetailRequest;
use App\Dto\Procurement\Process\ExecutionContext;

interface DetailSource
{
    public function fetch(DetailRequest $request, ExecutionContext $context): DetailFetchResult;
}
