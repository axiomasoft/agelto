<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Contracts;

use App\Dto\Procurement\Process\Outcomes\ValidatedStageResult;

interface StagePublicationPreparation
{
    /** Prepare idempotent infrastructure before the fenced publication transaction. */
    public function preparePublication(ValidatedStageResult $result): void;
}
