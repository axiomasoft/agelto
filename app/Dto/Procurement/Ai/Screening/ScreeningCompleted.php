<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Screening;

use App\Dto\Procurement\Process\Outcomes\ValidatedStageResult;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Process\Core\StageCode;

final readonly class ScreeningCompleted implements ValidatedStageResult
{
    public function __construct(public ProcessUuid $runId, public WorkId $sourceWorkId, public ScreeningResult $result) {}

    public function stage(): StageCode
    {
        return StageCode::Screening;
    }
}
