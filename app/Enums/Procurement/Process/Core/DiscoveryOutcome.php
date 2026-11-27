<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process\Core;

/** `prc_process_runs.discovery_outcome`: collecting ⇔ discovery_closed_at IS NULL (execution DDL CHECK). */
enum DiscoveryOutcome: string
{
    case Collecting = 'collecting';
    case Complete = 'complete';
    case Incomplete = 'incomplete';

    public function closed(): bool
    {
        return $this !== self::Collecting;
    }
}
