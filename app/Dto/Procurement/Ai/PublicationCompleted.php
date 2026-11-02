<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai;

use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\ValidatedStageResult;
use App\Enums\Procurement\Process\StageCode;

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
