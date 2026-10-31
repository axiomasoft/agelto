<?php

declare(strict_types=1);

namespace Tests\Support\Platforms\JsonProcurement;

use App\Dto\Procurement\Acquisition\DetailFetchResult;
use App\Dto\Procurement\Acquisition\DetailRequest;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Services\Procurement\Platform\Contracts\DetailSource;

final readonly class JsonDetailSource implements DetailSource
{
    public function __construct(private JsonProcurementSource $source) {}

    public function fetch(DetailRequest $request, ExecutionContext $context): DetailFetchResult
    {
        return $this->source->fetchDetail($request, $context);
    }
}
