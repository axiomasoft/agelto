<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Execution;

use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\ProcessValue;
use App\Dto\Procurement\Process\Primitives\WorkId;

/** Correlation IDs исполнения для trace/log context; без payload и token. */
final readonly class ExecutionIds
{
    public function __construct(
        public WorkId $workId,
        public ProcessUuid $attemptId,
        public int $generation,
    ) {
        ProcessValue::positive($generation);
    }
}
