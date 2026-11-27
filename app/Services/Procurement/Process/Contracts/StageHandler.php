<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Contracts;

use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Inputs\WorkInput;
use App\Dto\Procurement\Process\Outcomes\StageOutcome;

/**
 * Stage handler: typed input и ExecutionContext → один из пяти StageOutcome.
 * Expected dependency/control/provider исходы не выбрасываются как generic exception; handler не
 * пишет ledger и не публикует результат сам — это делает fenced WorkFinalizer.
 */
interface StageHandler
{
    public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome;
}
