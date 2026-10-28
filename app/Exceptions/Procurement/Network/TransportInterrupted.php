<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Network;

use App\Dto\Procurement\Network\HttpAttemptResult;

/** Runtime-only carrier: preserves partial byte evidence while all transport resources close. */
final class TransportInterrupted extends \RuntimeException
{
    public function __construct(public readonly HttpAttemptResult $result, public readonly \Throwable $interruption)
    {
        parent::__construct('Outbound transfer interrupted.', previous: $interruption);
    }
}
