<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Dto\Procurement\Process\ProcessUuid;

/** Last fully received HTTP error, bounded by its request profile; body is decoded UTF-8. */
final readonly class FinalHttpResponseEvidence
{
    public function __construct(public ProcessUuid $requestId, public int $status, public DecodedTextBody $body)
    {
        if ($status < 400 || $status > 599) {
            throw new \InvalidArgumentException('HTTP error evidence requires an error status.');
        }
    }
}
