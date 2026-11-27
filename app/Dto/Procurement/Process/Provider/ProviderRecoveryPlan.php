<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process\Provider;

use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\ProcessValue;
use App\Enums\Procurement\Process\Outcomes\ProviderOperationPurpose;
use App\Enums\Procurement\Process\Outcomes\ProviderRecoveryMode;

final readonly class ProviderRecoveryPlan
{
    public function __construct(
        public ProviderRecoveryMode $mode,
        public ProcessUuid $logicalRequestId,
        public ProcessUuid $physicalCallId,
        public ProviderOperationPurpose $purpose,
        public ?ReceiptRef $receipt,
        public ContentHash $expectedInputHash,
        public ContentHash $recipeHash,
        public int $requestEpoch,
        public int $planVersion = 1,
    ) {
        ProcessValue::positive($requestEpoch);
        if ($planVersion !== 1) {
            throw new \InvalidArgumentException('Unsupported provider recovery plan version.');
        }
        if (in_array($mode, [ProviderRecoveryMode::ResumeFromReceipt, ProviderRecoveryMode::ResumeProviderAsset], true) && $receipt === null) {
            throw new \InvalidArgumentException('Resume requires a verified pinned receipt.');
        }
        if (($mode === ProviderRecoveryMode::ResumeFromReceipt && $purpose !== ProviderOperationPurpose::Generation)
            || ($mode === ProviderRecoveryMode::ResumeProviderAsset && $purpose === ProviderOperationPurpose::Generation)) {
            throw new \InvalidArgumentException('Recovery mode does not match provider purpose.');
        }
    }
}
