<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process;

enum CapacityKind: string
{
    case Lane = 'lane';
    case Storage = 'storage';
}
