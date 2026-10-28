<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

final readonly class TransportSucceeded implements TransportOutcome
{
    public function __construct(public ResponseEnvelope $response) {}
}
