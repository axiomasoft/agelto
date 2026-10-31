<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Acquisition\DetailFetchResult;
use App\Dto\Procurement\Acquisition\DetailRequest;
use App\Dto\Procurement\Network\OutboundExecutionContext;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Services\Procurement\Platform\Contracts\DetailPartsSource;
use App\Services\Procurement\Platform\Contracts\StatusSource;

final class DetailFixtureSource implements DetailPartsSource, StatusSource
{
    /** @var list<string> */
    public array $calls = [];

    /**
     * @param  list<DetailFetchResult>  $responses
     * @param  non-empty-list<string>  $names
     */
    public function __construct(private array $responses, private array $names = ['attributes', 'documents', 'results']) {}

    public function refresh(DetailRequest $request, ExecutionContext|OutboundExecutionContext $context): DetailFetchResult
    {
        return $this->fetchPart($request, $this->names[0], $context);
    }

    public function parts(DetailRequest $request): array
    {
        return $this->names;
    }

    public function fetch(DetailRequest $request, ExecutionContext|OutboundExecutionContext $context): DetailFetchResult
    {
        return $this->fetchPart($request, $this->names[0], $context);
    }

    public function fetchPart(DetailRequest $request, string $part, ExecutionContext|OutboundExecutionContext $context): DetailFetchResult
    {
        $this->calls[] = $part;

        return array_shift($this->responses) ?? throw new \LogicException('Unexpected detail fetch.');
    }
}
