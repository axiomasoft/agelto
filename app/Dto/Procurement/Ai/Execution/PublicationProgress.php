<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Execution;

use App\Dto\Procurement\Process\Outcomes\StageCheckpoint;
use App\Enums\Procurement\Process\Core\StageCode;

/** Publication is a single DB-only commit; no uncommitted rows form a checkpoint. */
final readonly class PublicationProgress implements StageCheckpoint
{
    public function stage(): StageCode
    {
        return StageCode::Publication;
    }
}
