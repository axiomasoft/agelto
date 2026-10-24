<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use App\Enums\Procurement\Process\StageCode;

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
