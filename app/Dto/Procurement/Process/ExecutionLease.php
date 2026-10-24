<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

/** Decoded immutable ownership evidence; the future repository must fence every write. */
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
    ) {
        ProcessValue::positive($generation);
        ProcessValue::positive($inputRevision);
        ProcessValue::nonNegative($checkpointSeq);
        if ($checkpoint?->providerRecoveryPlan instanceof ProviderRecoveryPlan
            && $checkpoint->providerRecoveryPlan->expectedInputHash->hex !== $inputHash->hex) {
            throw new \InvalidArgumentException('Recovery directive belongs to a different immutable input.');
        }
    }
}
