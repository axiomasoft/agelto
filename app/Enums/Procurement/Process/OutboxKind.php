<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process;

/** Kind prc_process_outbox: каждый consumer claim-ит только свой kind (bp-sql-relay). */
enum OutboxKind: string
{
    case WorkWake = 'work_wake';
    case FeederWake = 'feeder_wake';
    case Projection = 'projection';
}
