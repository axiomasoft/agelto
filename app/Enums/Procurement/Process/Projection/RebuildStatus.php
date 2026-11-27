<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Process\Projection;

/** Исход `process:metrics-rebuild`; любой отказ оставляет прежнюю generation и receipts нетронутыми. */
enum RebuildStatus: string
{
    case Rebuilt = 'rebuilt';
    case Busy = 'busy';
    case HistoryUnavailable = 'history_unavailable';
    case CatalogMismatch = 'catalog_mismatch';
    case TooLarge = 'too_large';
    case Timeout = 'timeout';
    case UnsupportedFact = 'unsupported_fact';
    case Inconsistent = 'inconsistent';
}
