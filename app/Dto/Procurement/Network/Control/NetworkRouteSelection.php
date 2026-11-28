<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network\Control;

use App\Dto\Procurement\Process\Primitives\ProcessUuid;

/** An explicit direct route or exact profile membership; no default/direct fallback. */
final readonly class NetworkRouteSelection
{
    public function __construct(public ProcessUuid $routeId, public ?ProcessUuid $profileId = null, public int $credentialVersion = 1)
    {
        if ($credentialVersion < 1) {
            throw new \InvalidArgumentException('Invalid selected credential version.');
        }
    }
}
