<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Platform;

enum SnapshotConsistency: string
{
    case SourceRevisionVerified = 'source_revision_verified';
    case BoundedRevalidated = 'bounded_revalidated';
    case Unstable = 'unstable';
}
