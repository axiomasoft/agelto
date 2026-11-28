<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Execution;

use App\Enums\Procurement\Process\Outcomes\ClaimStatus;

/** Исход claim: lease существует ровно у Claimed, ни один другой исход не даёт ownership. */
final readonly class ClaimResult
{
    private function __construct(public ClaimStatus $status, public ?ExecutionLease $lease) {}

    public static function claimed(ExecutionLease $lease): self
    {
        return new self(ClaimStatus::Claimed, $lease);
    }

    public static function refused(ClaimStatus $status): self
    {
        if ($status === ClaimStatus::Claimed) {
            throw new \InvalidArgumentException('A claimed delivery requires its execution lease.');
        }

        return new self($status, null);
    }
}
