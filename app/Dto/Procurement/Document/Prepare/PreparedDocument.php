<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Document\Prepare;

use App\Dto\Procurement\Document\Download\DocumentHead;
use App\Dto\Procurement\Document\Storage\VerifiedArtifact;
use App\Dto\Procurement\Process\Outcomes\ValidatedStageResult;
use App\Enums\Procurement\Process\Core\StageCode;

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
