<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Screening;

use App\Dto\Llm\Requests\VerifiedReceipt;
use App\Dto\Procurement\Ai\Execution\RecoveredProviderAsset;
use App\Dto\Procurement\Ai\Extraction\DiscoveryCheckpoint;
use App\Dto\Procurement\Ai\Planning\PageRange;
use App\Dto\Procurement\Ai\Planning\PageSourceManifest;
use App\Dto\Procurement\Process\Outcomes\StageCheckpoint;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Provider\ConsumesProviderOperation;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Outcomes\BlockedReason;

final readonly class ScreeningProgress implements ConsumesProviderOperation, StageCheckpoint
{
    public function __construct(public int $reaskOrdinal = 0, public ?ScreeningResult $result = null,
        public ?BlockedReason $blocked = null, public ?ProcessUuid $physicalCallId = null, public ?VerifiedReceipt $receipt = null,
        public ?RecoveredProviderAsset $recoveredAsset = null, public ?DiscoveryCheckpoint $discovery = null,
        public ?PageRange $consumedRange = null, public ?PageSourceManifest $preparedSource = null)
    {
        if ($reaskOrdinal < 0 || $reaskOrdinal > 1 || ($result !== null && $blocked !== null && $discovery === null)
            || (($physicalCallId === null) !== ($receipt === null))) {
            throw new \InvalidArgumentException('Invalid bounded screening progress.');
        }
        if ($recoveredAsset !== null && ($blocked !== null || $consumedRange !== null || $receipt !== $recoveredAsset->receipt
            || $physicalCallId?->value !== $receipt->call->authorization->physicalCallId)) {
            throw new \InvalidArgumentException('Asset recovery cannot accept screening output.');
        }
        if ($preparedSource !== null && ($result !== null || $blocked !== null || $physicalCallId !== null || $discovery !== null)) {
            throw new \InvalidArgumentException('Source materialization precedes every provider operation.');
        }
    }

    public function stage(): StageCode
    {
        return StageCode::Screening;
    }

    public function consumedPhysicalCallId(): ?ProcessUuid
    {
        return $this->physicalCallId;
    }

    public function withRecoveredAsset(RecoveredProviderAsset $recovery): self
    {
        return new self($this->reaskOrdinal, $this->result, physicalCallId: new ProcessUuid($recovery->receipt->call->authorization->physicalCallId),
            receipt: $recovery->receipt, recoveredAsset: $recovery, discovery: $this->discovery);
    }
}
