<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Document;

enum StoragePhase: string
{
    case Download = 'download';
    case Prepare = 'prepare';
}
