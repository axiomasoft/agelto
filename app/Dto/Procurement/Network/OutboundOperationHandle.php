<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\OutboundOperationKind;

/**
 * Fenced identity открытой standalone outbound operation: (id, generation, owner token).
 * claimSend сверяет все три плюс live lease и фиксированный deadline; handle сам права не даёт.
 */
final readonly class OutboundOperationHandle
{
    public function __construct(
        public ProcessUuid $id,
        public OutboundOperationKind $kind,
        public int $generation,
        public ProcessUuid $ownerToken,
        public \DateTimeImmutable $leaseUntil,
        public \DateTimeImmutable $deadlineAt,
    ) {
        if ($generation < 1) {
            throw new \InvalidArgumentException('Operation generation starts at 1.');
        }
        if ($leaseUntil > $deadlineAt) {
            throw new \InvalidArgumentException('Operation lease cannot outlive its fixed deadline.');
        }
    }

    public function withLease(\DateTimeImmutable $leaseUntil): self
    {
        return new self($this->id, $this->kind, $this->generation, $this->ownerToken, $leaseUntil, $this->deadlineAt);
    }
}
