<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use App\Dto\Procurement\Process\StageCheckpoint;
use App\Dto\Procurement\Process\ValidatedStageResult;
use App\Dto\Procurement\Process\VersionedPayload;
use App\Enums\Procurement\Process\StageCode;

/** Explicit serialization seam; stage algorithms are not part of Process. */
interface StagePayloadCodec
{
    public function stage(): StageCode;

    /**
     * Progress до первого сохранённого checkpoint: recovery directive (WorkCheckpointEnvelope)
     * не существует без stageProgress, а работа без устойчивого прогресса его тоже должна нести.
     */
    public function initialCheckpoint(): StageCheckpoint;

    public function encodeCheckpoint(StageCheckpoint $checkpoint): VersionedPayload;

    public function decodeCheckpoint(VersionedPayload $payload): StageCheckpoint;

    public function encodeResult(ValidatedStageResult $result): VersionedPayload;

    public function decodeResult(VersionedPayload $payload): ValidatedStageResult;
}
