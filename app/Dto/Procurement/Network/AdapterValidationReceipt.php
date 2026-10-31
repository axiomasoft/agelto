<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

use App\Dto\Procurement\Process\ContentHash;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Network\AdapterValidationOwnerKind;
use App\Enums\Procurement\Network\AdapterVerdict;

/** Immutable provenance for downstream validation fences; no response bodies or credentials. */
final readonly class AdapterValidationReceipt
{
    /** @var list<ProcessUuid> */
    public array $requestIds;

    /** @param array<array-key,ProcessUuid> $requestIds */
    public function __construct(public ProcessUuid $healthId, public int $epoch, public AdapterContractRef $contract,
        public ContentHash $inputHash, public ProcessUuid $observationId, public ProcessUuid $ownerId,
        array $requestIds, public bool $stale = false, public AdapterVerdict $verdict = AdapterVerdict::Accepted,
        public AdapterValidationOwnerKind $ownerKind = AdapterValidationOwnerKind::OutboundOperation)
    {
        if ($epoch < 1 || ! array_is_list($requestIds) || $requestIds === [] || count($requestIds) > 64) {
            throw new \InvalidArgumentException('Invalid full-validation receipt.');
        }
        $this->requestIds = $requestIds;
    }
}
