<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Contracts;

use App\Dto\Procurement\Process\Inputs\WorkInput;
use App\Dto\Procurement\Process\Outcomes\ValidatedStageResult;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;

interface StagePublicationRepository extends StageResultRepository
{
    public function publicationLocks(WorkInput $input, ValidatedStageResult $result): PublicationLocks;

    /** All successor work and inherited links already exist in the caller transaction. */
    public function afterSuccessors(WorkInput $input, WorkId $workId, ProcessUuid $eventId, ProcessUuid $projectionId): void;
}
