<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai;

use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\ValidatedStageResult;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\StageCode;

final readonly class ScreeningCompleted implements ValidatedStageResult
{
    public function __construct(public ProcessUuid $runId, public WorkId $sourceWorkId, public ScreeningResult $result) {}

    public function stage(): StageCode
    {
        return StageCode::Screening;
    }
}
