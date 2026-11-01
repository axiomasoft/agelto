<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document;

use App\Dto\Procurement\Process\ValidatedStageResult;
use App\Enums\Procurement\Process\StageCode;

final readonly class PreparedDocument implements ValidatedStageResult
{
    public function __construct(public DocumentHead $head, public PrepareOcrCheckpoint $manifest, public VerifiedArtifact $text)
    {
        if (! $manifest->coverage()->complete() || $head->versionId === null) {
            throw new \InvalidArgumentException('Required coverage is incomplete.');
        }
    }

    public function stage(): StageCode
    {
        return StageCode::Prepare;
    }
}
