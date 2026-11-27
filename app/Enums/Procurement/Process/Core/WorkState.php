<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process\Core;

enum WorkState: string
{
    case Ready = 'ready';
    case Admitted = 'admitted';
    case Running = 'running';
    case Deferred = 'deferred';
    case Blocked = 'blocked';
    case Succeeded = 'succeeded';
    case FailedItem = 'failed_item';
    case Cancelled = 'cancelled';
    case Superseded = 'superseded';
}
