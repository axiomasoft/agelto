<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process\Contracts;

use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\StageOutcome;
use App\Dto\Procurement\Process\WorkInput;

/**
 * Stage handler (execution#s03): typed input и ExecutionContext → один из пяти StageOutcome.
 * Expected dependency/control/provider исходы не выбрасываются как generic exception; handler не
 * пишет ledger и не публикует результат сам — это делает fenced WorkFinalizer.
 */
interface StageHandler
{
    public function execute(WorkInput $input, ExecutionContext $execution): StageOutcome;
}
