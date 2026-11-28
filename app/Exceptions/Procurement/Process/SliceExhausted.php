<?php

declare(strict_types=1);

namespace App\Exceptions\Procurement\Process;

/**
 * Локальный monotonic slice исчерпан: handler сохраняет только уже устойчивый checkpoint и
 * возвращает StagePartial либо StageDeferred(slice_exhausted), без item/transport cost.
 */
final class SliceExhausted extends ExecutionInterrupted
{
    public static function forWork(string $workId): self
    {
        return new self('Execution slice exhausted for work '.$workId.'.');
    }
}
