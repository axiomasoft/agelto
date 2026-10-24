<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Procurement\Process\ArtifactRef;
use App\Dto\Procurement\Process\ValidatedStageResult;
use App\Enums\Procurement\Process\StageCode;

final readonly class FixtureStageResult implements ValidatedStageResult
{
    public function __construct(public ArtifactRef $artifact, public StageCode $stageCode = StageCode::Listing) {}

    public function stage(): StageCode
    {
        return $this->stageCode;
    }
}
