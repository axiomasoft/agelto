<?php

declare(strict_types=1);

namespace App\Enums\Procurement;

enum DetailSnapshotStatus: string
{
    case Complete = 'complete';
    case Incomplete = 'incomplete';
    case Failed = 'failed';
}
