<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

final readonly class WorkCheckpointEnvelope
{
    public function __construct(
        public StageCheckpoint $stageProgress,
        public NoProviderRecovery|ProviderRecoveryPlan $providerRecoveryPlan,
    ) {}

    public function withProgress(StageCheckpoint $progress): self
    {
        if ($progress->stage() !== $this->stageProgress->stage()) {
            throw new \InvalidArgumentException('Checkpoint stage cannot change.');
        }

        return new self($progress, $this->providerRecoveryPlan);
    }

    public function withoutProviderRecovery(): self
    {
        return new self($this->stageProgress, new NoProviderRecovery);
    }
}
