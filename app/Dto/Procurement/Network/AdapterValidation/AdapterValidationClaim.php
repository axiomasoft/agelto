<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network\AdapterValidation;

use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Procurement\Network\AdapterValidationOwnerKind;

/** A live attempt or bounded operation owns full validation; permits cover each physical transfer. */
final readonly class AdapterValidationClaim
{
    public function __construct(public ProcessUuid $healthId, public int $epoch, public AdapterContractRef $contract,
        public ProcessUuid $ownerId, public bool $canary,
        public AdapterValidationOwnerKind $ownerKind = AdapterValidationOwnerKind::OutboundOperation)
    {
        if ($epoch < 1 || ($canary && $ownerKind === AdapterValidationOwnerKind::WorkAttempt)) {
            throw new \InvalidArgumentException('Invalid adapter health epoch.');
        }
    }

    public function epochKey(): string
    {
        return 'prc_adapter_health:'.$this->healthId->value;
    }
}
