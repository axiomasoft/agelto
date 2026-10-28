<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Dto\Procurement\Process\ArtifactRef;

/** Binary тело, записанное bounded sink в storage; документ никогда не держится UTF-8 строкой. */
final readonly class BinaryBodyArtifact implements ResponseBody
{
    public function __construct(public ArtifactRef $artifact, public int $bytes)
    {
        if ($bytes < 0) {
            throw new \InvalidArgumentException('Binary body size must not be negative.');
        }
    }
}
