<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Outcomes;

use App\Dto\Procurement\Process\Execution\CompletionResult;
use App\Enums\Procurement\Process\Core\WorkState;

/** Результат WorkFinalizationRepository::finishLocked() без out-параметров по ссылке. */
final readonly class FinishLockedResult
{
    /**
     * @param  array{state: WorkState, reason: string|null, due_at: string|null, item_increment: int}|null  $accepted
     */
    public function __construct(
        public CompletionResult $completion,
        public bool $membershipCorrupt = false,
        public ?array $accepted = null,
    ) {}
}
