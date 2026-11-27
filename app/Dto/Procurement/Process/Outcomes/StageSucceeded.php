<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Outcomes;

final readonly class StageSucceeded implements StageOutcome
{
    public function __construct(public ValidatedStageResult $result) {}
}
