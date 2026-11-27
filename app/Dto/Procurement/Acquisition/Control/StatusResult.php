<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Acquisition\Control;

use App\Dto\Procurement\Acquisition\Detail\ProcurementSnapshot;
use App\Dto\Procurement\Process\Outcomes\ValidatedStageResult;
use App\Enums\Procurement\Process\Core\StageCode;

final readonly class StatusResult implements ValidatedStageResult
{
    public function __construct(public ProcurementSnapshot $snapshot, public \DateTimeImmutable $observedAt)
    {
        if (! $snapshot->complete() || $snapshot->title === '' || $snapshot->sourceStage === '') {
            throw new \InvalidArgumentException('Incomplete status observation.');
        }
    }

    public function stage(): StageCode
    {
        return StageCode::StatusRefresh;
    }
}
