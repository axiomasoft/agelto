<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use App\Enums\Procurement\Process\StageCode;

/**
 * Decoded immutable ownership evidence claimed attempt (bp-sql-claim): work/generation/token/
 * attempt, input fingerprint, executing handler version и decoded checkpoint envelope. Repository
 * fence-ит каждую запись по generation+token; это не mutable model reference.
 */
final readonly class ExecutionLease
{
    public function __construct(
        public WorkId $workId,
        public ProcessUuid $attemptId,
        public int $generation,
        public ProcessUuid $token,
        public \DateTimeImmutable $leaseUntil,
        public int $inputRevision,
        public ContentHash $inputHash,
        public int $checkpointSeq,
        public ?WorkCheckpointEnvelope $checkpoint,
        public StageCode $stage,
        public string $handlerVersion,
    ) {
        ProcessValue::positive($generation);
        ProcessValue::positive($inputRevision);
        ProcessValue::nonNegative($checkpointSeq);
        ProcessValue::text($handlerVersion);
        if ($checkpoint?->providerRecoveryPlan instanceof ProviderRecoveryPlan
            && $checkpoint->providerRecoveryPlan->expectedInputHash->hex !== $inputHash->hex) {
            throw new \InvalidArgumentException('Recovery directive belongs to a different immutable input.');
        }
        if ($checkpoint !== null && $checkpoint->stageProgress->stage() !== $stage) {
            throw new \InvalidArgumentException('Checkpoint progress belongs to a different stage.');
        }
    }

    /** Progress checkpoint/partial обязан принадлежать stage этой work до любой записи. */
    public function guardProgress(StageCheckpoint $progress): StageCheckpoint
    {
        if ($progress->stage() !== $this->stage) {
            throw new \InvalidArgumentException('Checkpoint progress belongs to a different stage.');
        }

        return $progress;
    }

    /** Тот же owner после успешного heartbeat CAS: меняется только DB lease_until. */
    public function renewed(DbInstant $leaseUntil): self
    {
        return $this->with($leaseUntil->value, $this->checkpointSeq, $this->checkpoint);
    }

    /** Тот же owner после Saved checkpoint: новый seq, merged envelope и продлённый lease. */
    public function checkpointed(CheckpointResult $saved): self
    {
        if ($saved->checkpointSeq === null || $saved->leaseUntil === null || $saved->checkpoint === null) {
            throw new \InvalidArgumentException('Only a saved checkpoint advances the lease.');
        }

        return $this->with($saved->leaseUntil->value, $saved->checkpointSeq, $saved->checkpoint);
    }

    private function with(\DateTimeImmutable $leaseUntil, int $checkpointSeq, ?WorkCheckpointEnvelope $checkpoint): self
    {
        return new self(
            $this->workId, $this->attemptId, $this->generation, $this->token, $leaseUntil, $this->inputRevision,
            $this->inputHash, $checkpointSeq, $checkpoint, $this->stage, $this->handlerVersion,
        );
    }
}
