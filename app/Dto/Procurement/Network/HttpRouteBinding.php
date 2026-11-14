<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Dto\Procurement\Process\ProcessUuid;

/** Identity carried by a consumer profile snapshot. Credentials belong to Proxyma. */
final readonly class HttpRouteBinding
{
    public function __construct(public ProcessUuid $id, public int $credentialVersion = 1)
    {
        if ($credentialVersion < 1) {
            throw new \InvalidArgumentException('Invalid credential version.');
        }
    }
}
