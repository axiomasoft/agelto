<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Exceptions\Procurement\Network\ResponseLimitExceeded;

/** One logical operation, including retries/hops; wire and decompressed limits are independent. */
final class TransferBudget
{
    private int $wire = 0;

    private int $entity = 0;

    public function __construct(public readonly int $maxWireBytes, public readonly int $maxEntityBytes)
    {
        if (min($maxWireBytes, $maxEntityBytes) < 1) {
            throw new \InvalidArgumentException('Transfer budgets must be positive.');
        }
    }

    public function wire(int $bytes): void
    {
        $this->wire += max(0, $bytes);
        if ($this->wire > $this->maxWireBytes) {
            throw new ResponseLimitExceeded('Operation wire byte limit exceeded.');
        }
    }

    public function entity(int $bytes): void
    {
        $this->entity += max(0, $bytes);
        if ($this->entity > $this->maxEntityBytes) {
            throw new ResponseLimitExceeded('Operation entity byte limit exceeded.');
        }
    }
}
