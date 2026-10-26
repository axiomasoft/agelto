<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use App\Enums\Procurement\Process\CompletionStatus;
use App\Enums\Procurement\Process\WorkState;

/** Исход finalize: новое состояние существует ровно у Applied. */
final readonly class CompletionResult
{
    private function __construct(public CompletionStatus $status, public ?WorkState $state) {}

    public static function applied(WorkState $state): self
    {
        return new self(CompletionStatus::Applied, $state);
    }

    public static function lostOwnership(): self
    {
        return new self(CompletionStatus::LostOwnership, null);
    }

    public static function invariantViolation(): self
    {
        return new self(CompletionStatus::InvariantViolation, null);
    }
}
