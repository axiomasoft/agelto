<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Dto\Procurement\Process\ProcessUuid;

/** An explicit direct route or exact profile membership; no default/direct fallback. */
final readonly class NetworkRouteSelection
{
    public function __construct(public ProcessUuid $routeId, public ?ProcessUuid $profileId = null) {}
}
