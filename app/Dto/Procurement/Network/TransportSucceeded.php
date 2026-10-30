<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Dto\Procurement\Process\ProcessUuid;

final readonly class TransportSucceeded implements TransportOutcome
{
    public function __construct(public ResponseEnvelope $response, public ?ProcessUuid $physicalRequestId = null) {}
}
