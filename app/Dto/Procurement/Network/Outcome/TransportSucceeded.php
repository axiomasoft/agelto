<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network\Outcome;

use App\Dto\Procurement\Network\Http\ResponseEnvelope;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;

final readonly class TransportSucceeded implements TransportOutcome
{
    public function __construct(public ResponseEnvelope $response, public ?ProcessUuid $physicalRequestId = null) {}
}
