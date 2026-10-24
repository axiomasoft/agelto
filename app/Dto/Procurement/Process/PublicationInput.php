<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use App\Enums\Procurement\Process\StageCode;

final readonly class PublicationInput implements WorkInput
{
    public function __construct(
        public ProcessUuid $analysisRunId,
        public ContentHash $recipeHash,
        public int $resultGeneration,
    ) {
        ProcessValue::positive($resultGeneration);
    }

    public function stage(): StageCode
    {
        return StageCode::Publication;
    }
}
