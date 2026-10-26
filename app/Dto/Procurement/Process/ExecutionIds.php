<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

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
