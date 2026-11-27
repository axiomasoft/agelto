<?php

declare(strict_types=1);

namespace Tests\Support\Platforms\JsonProcurement;

use App\Dto\Procurement\Acquisition\Detail\DetailFetchResult;
use App\Dto\Procurement\Acquisition\Detail\DetailRequest;
use App\Dto\Procurement\Network\Control\OutboundExecutionContext;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Services\Procurement\Platform\Contracts\DetailPartsSource;

final readonly class JsonDetailSource implements DetailPartsSource
{
    public function __construct(private JsonProcurementSource $source) {}

    public function fetch(DetailRequest $request, ExecutionContext|OutboundExecutionContext $context): DetailFetchResult
    {
        return $this->source->fetchDetail($request, $context);
    }

    public function parts(DetailRequest $request): array
    {
        return ['record'];
    }

    public function fetchPart(DetailRequest $request, string $part, ExecutionContext|OutboundExecutionContext $context): DetailFetchResult
    {
        if ($part !== 'record') {
            throw new \InvalidArgumentException('Unknown JSON detail part.');
        }

        return $this->fetch($request, $context);
    }
}
