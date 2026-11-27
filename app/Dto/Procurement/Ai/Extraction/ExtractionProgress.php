<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Extraction;

use App\Dto\Llm\Requests\VerifiedReceipt;
use App\Dto\Procurement\Ai\Execution\RecoveredProviderAsset;
use App\Dto\Procurement\Ai\Planning\PageRange;
use App\Dto\Procurement\Ai\Planning\RowRange;
use App\Dto\Procurement\Process\Outcomes\StageCheckpoint;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Provider\ConsumesProviderOperation;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Enums\Procurement\Process\Outcomes\BlockedReason;

/** The proposed chunk is transient; the persisted checkpoint contains references only. */
final readonly class ExtractionProgress implements ConsumesProviderOperation, StageCheckpoint
{
    /**
     * @param  array<string,int>  $depths
     * @param  array<string,int>  $reasks
     */
    public function __construct(
        public ?ExtractionCheckpoint $checkpoint = null,
        public array $depths = [],
        public array $reasks = [],
        public ?BlockedReason $blocked = null,
        public ?ValidatedChunk $chunk = null,
        public ?ProcessUuid $physicalCallId = null,
        public ?VerifiedReceipt $receipt = null,
        public RowRange|PageRange|null $consumedRange = null,
        public ?RecoveredProviderAsset $recoveredAsset = null,
    ) {
        foreach ([$depths, $reasks] as $index => $map) {
            if (count($map) > 511) {
                throw new \InvalidArgumentException('Extraction progress map exceeds the split tree bound.');
            }
            foreach ($map as $key => $ordinal) {
                if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1 || $ordinal < 0 || $ordinal > ($index === 0 ? 16 : 1)) {
                    throw new \InvalidArgumentException('Invalid bounded extraction progress.');
                }
            }
        }
        if ($chunk !== null && ($checkpoint === null || $physicalCallId === null)) {
            throw new \InvalidArgumentException('Chunk requires explicit progress and operation consumption.');
        }
        if ($recoveredAsset !== null && ($chunk !== null || $consumedRange !== null || $blocked !== null
            || $receipt !== $recoveredAsset->receipt || $physicalCallId?->value !== $receipt->call->authorization->physicalCallId)) {
            throw new \InvalidArgumentException('Asset recovery cannot accept model output.');
        }
    }

    public function stage(): StageCode
    {
        return StageCode::Extraction;
    }

    public function consumedPhysicalCallId(): ?ProcessUuid
    {
        return $this->physicalCallId;
    }

    public function withRecoveredAsset(RecoveredProviderAsset $recovery): self
    {
        return new self($this->checkpoint, $this->depths, $this->reasks, $this->blocked, physicalCallId: new ProcessUuid($recovery->receipt->call->authorization->physicalCallId), receipt: $recovery->receipt, recoveredAsset: $recovery);
    }
}
