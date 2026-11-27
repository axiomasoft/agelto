<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Execution;

use App\Dto\Procurement\Process\Outcomes\ValidatedStageResult;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Process\Core\StageCode;

/** A fingerprint of the complete immutable source plan and accepted chunk set. */
final readonly class PublicationCompleted implements ValidatedStageResult
{
    public function __construct(public ProcessUuid $runId, public ContentHash $recipeHash,
        public int $generation, public ContentHash $digest) {}

    public function stage(): StageCode
    {
        return StageCode::Publication;
    }
}
