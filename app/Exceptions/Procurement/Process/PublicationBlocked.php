<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Process;

use App\Enums\Procurement\Process\Outcomes\BlockedReason;

/** Rejected domain publication: rollback first, then settle through the ordinary owner fence. */
final class PublicationBlocked extends \RuntimeException
{
    public function __construct(public readonly BlockedReason $reason, ?\Throwable $previous = null)
    {
        parent::__construct('Domain publication is blocked: '.$reason->value, previous: $previous);
    }
}
