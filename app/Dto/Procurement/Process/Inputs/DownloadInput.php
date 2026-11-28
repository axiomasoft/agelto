<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Inputs;

use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\ProcessValue;
use App\Enums\Procurement\Process\Core\StageCode;

final readonly class DownloadInput implements WorkInput
{
    public function __construct(
        public int $documentId,
        public ProcessUuid $acquisitionIntentId,
        public int $expectedDocumentHeadGeneration,
        public ContentHash $accessPolicyHash,
    ) {
        ProcessValue::positive($documentId);
        ProcessValue::positive($expectedDocumentHeadGeneration);
    }

    public function stage(): StageCode
    {
        return StageCode::Download;
    }
}
