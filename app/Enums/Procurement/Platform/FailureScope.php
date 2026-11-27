<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Platform;

enum FailureScope: string
{
    case None = 'none';
    case Adapter = 'adapter';
    case Item = 'item';
    case Origin = 'origin';
}
