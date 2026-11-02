<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai;

use App\Dto\Procurement\Process\ValidatedStageResult;
use App\Enums\Procurement\Process\StageCode;

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
