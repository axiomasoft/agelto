<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Inputs;

use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\ProcessValue;
use App\Enums\Procurement\Process\Core\StageCode;

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
