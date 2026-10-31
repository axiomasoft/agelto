<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition;

use App\Dto\Procurement\Process\ValidatedStageResult;
use App\Enums\Procurement\Platform\SnapshotConsistency;
use App\Enums\Procurement\Process\StageCode;

final readonly class DetailResult implements ValidatedStageResult
{
    public function __construct(public ProcurementSnapshot $snapshot, public DetailProgress $progress,
        public SnapshotConsistency $consistency, public \DateTimeImmutable $observedAt)
    {
        if (! $snapshot->complete() || $snapshot->title === '' || $snapshot->sourceStage === '' || $consistency === SnapshotConsistency::Unstable) {
            throw new \InvalidArgumentException('Unpublishable detail snapshot.');
        }
    }

    public function stage(): StageCode
    {
        return StageCode::Detail;
    }
}
