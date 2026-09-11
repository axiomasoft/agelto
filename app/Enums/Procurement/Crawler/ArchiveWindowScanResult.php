<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Crawler;

enum ArchiveWindowScanResult
{
    case Drained;
    case Checkpointed;
    case OperatorDebt;
    case Stopped;
}
