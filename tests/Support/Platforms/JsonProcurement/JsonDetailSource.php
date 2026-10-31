<?php

declare(strict_types=1);

namespace Tests\Support\Platforms\JsonProcurement;

use App\Dto\Procurement\Acquisition\DetailFetchResult;
use App\Dto\Procurement\Acquisition\DetailRequest;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Services\Procurement\Platform\Contracts\DetailPartsSource;

final readonly class JsonDetailSource implements DetailPartsSource
{
    public function __construct(private JsonProcurementSource $source) {}

    public function fetch(DetailRequest $request, ExecutionContext $context): DetailFetchResult
    {
        return $this->source->fetchDetail($request, $context);
    }

    public function parts(DetailRequest $request): array
    {
        return ['record'];
    }

    public function fetchPart(DetailRequest $request, string $part, ExecutionContext $context): DetailFetchResult
    {
        if ($part !== 'record') {
            throw new \InvalidArgumentException('Unknown JSON detail part.');
        }

        return $this->fetch($request, $context);
    }
}
