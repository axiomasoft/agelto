<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Dto\Procurement\Process\ProcessUuid;

/** Gate selects only eligible routes in the caller's exact profile/default/explicit-direct set. */
final readonly class RetryRoutePreference
{
    /** @param list<string> $triedRouteIds */
    public function __construct(
        public array $triedRouteIds,
        public ?ProcessUuid $currentRoute,
        public bool $preferUntried,
        public int $maxDistinctRoutes,
    ) {}
}
