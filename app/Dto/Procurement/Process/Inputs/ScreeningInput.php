<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Inputs;

use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessValue;
use App\Enums\Procurement\Process\Core\StageCode;

final readonly class ScreeningInput implements WorkInput
{
    public function __construct(
        public int $documentVersionId,
        public ContentHash $representationHash,
        public ContentHash $screeningRecipeHash,
    ) {
        ProcessValue::positive($documentVersionId);
    }

    public function stage(): StageCode
    {
        return StageCode::Screening;
    }
}
