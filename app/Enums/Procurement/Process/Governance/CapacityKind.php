<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process\Governance;

enum CapacityKind: string
{
    case Lane = 'lane';
    case Storage = 'storage';
}
