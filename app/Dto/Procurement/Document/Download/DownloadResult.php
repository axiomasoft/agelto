<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document\Download;

use App\Dto\Procurement\Document\Storage\VerifiedArtifact;
use App\Dto\Procurement\Process\Outcomes\ValidatedStageResult;
use App\Enums\Procurement\Process\Core\StageCode;

final readonly class DownloadResult implements ValidatedStageResult
{
    public function __construct(public DocumentHead $head, public VerifiedArtifact $artifact, public SourceDocumentMetadata $metadata) {}

    public function stage(): StageCode
    {
        return StageCode::Download;
    }
}
