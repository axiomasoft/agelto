<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use App\Enums\Procurement\Process\StageCode;

final readonly class ExtractionWindowInput implements WorkInput
{
    public function __construct(
        public ProcessUuid $analysisRunId,
        public string $windowKey,
        public ContentHash $recipeHash,
    ) {
        ProcessValue::text($windowKey);
    }

    public function stage(): StageCode
    {
        return StageCode::Extraction;
    }
}
