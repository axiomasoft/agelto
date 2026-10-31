<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform;

use App\Dto\Procurement\Acquisition\ListingRequest;

final readonly class ProbePlan
{
    /** @var list<ListingRequest> */
    public array $requests;

    /** @param array<array-key, mixed> $requests */
    public function __construct(array $requests)
    {
        if (! array_is_list($requests) || count($requests) < 1 || count($requests) > 2) {
            throw new \InvalidArgumentException('A diagnostic plan requires one or two bounded reads.');
        }
        foreach ($requests as $request) {
            if (! $request instanceof ListingRequest) {
                throw new \InvalidArgumentException('Invalid diagnostic request.');
            }
        }
        $this->requests = $requests;
    }
}
