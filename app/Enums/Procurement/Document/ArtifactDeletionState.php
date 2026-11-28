<?php

declare(strict_types=1);

namespace App\Enums\Procurement\Document;

enum ArtifactDeletionState
{
    case Ready;
    case Deleted;
    case Stale;
}
