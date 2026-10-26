<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use App\Enums\Procurement\Process\CheckpointStatus;

/**
 * Исход checkpoint: только Saved несёт новый checkpoint_seq, DB lease_until и сохранённый merged
 * envelope; остальные исходы откатили все записи этой tx.
 */
final readonly class CheckpointResult
{
    private function __construct(
        public CheckpointStatus $status,
        public ?int $checkpointSeq,
        public ?DbInstant $leaseUntil,
        public ?WorkCheckpointEnvelope $checkpoint,
    ) {}

    public static function saved(int $checkpointSeq, DbInstant $leaseUntil, WorkCheckpointEnvelope $checkpoint): self
    {
        ProcessValue::positive($checkpointSeq);

        return new self(CheckpointStatus::Saved, $checkpointSeq, $leaseUntil, $checkpoint);
    }

    public static function refused(CheckpointStatus $status): self
    {
        if ($status === CheckpointStatus::Saved) {
            throw new \InvalidArgumentException('A saved checkpoint requires its sequence and lease.');
        }

        return new self($status, null, null, null);
    }
}
