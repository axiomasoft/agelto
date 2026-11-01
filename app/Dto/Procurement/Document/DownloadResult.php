<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document;

use App\Dto\Procurement\Process\ValidatedStageResult;
use App\Enums\Procurement\Process\StageCode;

final readonly class DownloadResult implements ValidatedStageResult
{
    public function __construct(public DocumentHead $head, public VerifiedArtifact $artifact, public SourceDocumentMetadata $metadata) {}

    public function stage(): StageCode
    {
        return StageCode::Download;
    }
}
