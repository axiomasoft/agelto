<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Extraction;

use App\Dto\Procurement\Process\Outcomes\ValidatedStageResult;
use App\Enums\Procurement\Process\Core\StageCode;

final readonly class ExtractionCompleted implements ValidatedStageResult
{
    public function __construct(public ExtractionCheckpoint $checkpoint)
    {
        if ($checkpoint->pending !== [] || $checkpoint->acceptedChunks === []) {
            throw new \InvalidArgumentException('Extraction requires accepted complete coverage.');
        }
    }

    public function stage(): StageCode
    {
        return StageCode::Extraction;
    }
}
