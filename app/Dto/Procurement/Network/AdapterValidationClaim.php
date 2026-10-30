<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Dto\Procurement\Process\ProcessUuid;

/** A live operation owns full validation; HTTP permits cover only each physical transfer. */
final readonly class AdapterValidationClaim
{
    public function __construct(public ProcessUuid $healthId, public int $epoch, public AdapterContractRef $contract,
        public ProcessUuid $operationId, public bool $canary)
    {
        if ($epoch < 1) {
            throw new \InvalidArgumentException('Invalid adapter health epoch.');
        }
    }

    public function epochKey(): string
    {
        return 'prc_adapter_health:'.$this->healthId->value;
    }
}
