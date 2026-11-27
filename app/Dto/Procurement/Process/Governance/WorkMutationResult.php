<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Governance;

use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Process\Outcomes\WorkMutationStatus;

/** Результат управляющей мутации; successor задан только у применённого manual retry. */
final readonly class WorkMutationResult
{
    public function __construct(public WorkMutationStatus $status, public ?WorkId $successor = null) {}

    public static function refused(WorkMutationStatus $status): self
    {
        if ($status === WorkMutationStatus::Applied) {
            throw new \InvalidArgumentException('Applied is not a refusal.');
        }

        return new self($status);
    }
}
