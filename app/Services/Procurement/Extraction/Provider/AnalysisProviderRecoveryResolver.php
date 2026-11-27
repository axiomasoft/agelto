<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction\Provider;

use App\Dto\Llm\Requests\VerifiedReceipt;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Dto\Procurement\Process\Provider\ProviderRecoveryPlan;
use App\Dto\Procurement\Process\Provider\ReceiptRef;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\ReceiptOutcome;
use App\Enums\Procurement\Process\Outcomes\BlockedReason;
use App\Enums\Procurement\Process\Outcomes\ProviderOperationPurpose;
use App\Enums\Procurement\Process\Outcomes\ProviderRecoveryMode;
use App\Exceptions\Llm\ReceiptUnavailable;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Exceptions\Procurement\Process\ProviderLinkageUnavailable;
use App\Repositories\Llm\Request\RequestRepository;
use App\Repositories\Procurement\Ai\AnalysisRequestRepository;
use App\Services\Llm\Execution\ReceiptStore;
use App\Services\Procurement\Process\Contracts\ProviderRecoveryPolicy;
use App\Services\Procurement\Process\Contracts\ProviderRecoveryResolver;

/** Purpose-aware bridge to the generic Llm ledger. No send, lookup HTTP or business writes. */
final class AnalysisProviderRecoveryResolver implements ProviderRecoveryPolicy, ProviderRecoveryResolver
{
    /** @var array<string, VerifiedReceipt> Verified outside locks, used only by the immediately guarded transaction. */
    private array $verified = [];

    public function __construct(private readonly AnalysisRequestRepository $mapping, private readonly RequestRepository $requests, private readonly ReceiptStore $receipts) {}

    public function inspect(WorkId $workId, ?ProviderRecoveryPlan $pinned): ?ProviderRecoveryPlan
    {
        // One scan candidate at a time; do not accumulate raw bodies in a long-lived daemon.
        $this->verified = [];
        $mapping = $this->mapping->active($workId);
        if ($mapping === null) {
            if ($pinned !== null) {
                throw new ProviderLinkageUnavailable('Pinned provider linkage missing.');
            }

            return null;
        }
        $call = $this->requests->get($mapping->physicalCallId);
        if ($call->authorization->logicalRequestId !== $mapping->logicalRequestId || $call->recipeHash !== $mapping->recipeHash->hex) {
            throw new ProviderLinkageUnavailable('Analysis/provider identity mismatch.');
        }
        try {
            $receipt = $this->receipts->inspect($call);
        } catch (ReceiptUnavailable $failure) {
            throw new DependencyUnavailable('storage', previous: $failure);
        }
        $mode = ProviderRecoveryMode::BlockedUnknown;
        if (! $call->claimed || $call->outcome === 'not_sent') {
            $mode = ProviderRecoveryMode::KnownRetryEligible;
        } elseif ($receipt !== null) {
            $this->verified[$call->authorization->physicalCallId] = $receipt;
            $mode = match ($receipt->assessment->outcome) {
                ReceiptOutcome::Success => $call->purpose === OperationPurpose::Generate ? ProviderRecoveryMode::ResumeFromReceipt : ProviderRecoveryMode::ResumeProviderAsset,
                ReceiptOutcome::NonbillableCacheMiss, ReceiptOutcome::NonbillableRetry => ProviderRecoveryMode::KnownRetryEligible,
                // Billed/terminal errors cannot become model output or a transport retry.
                ReceiptOutcome::BilledError, ReceiptOutcome::Unknown => ProviderRecoveryMode::BlockedUnknown,
            };
        }
        $ref = $receipt === null ? null : new ReceiptRef(new ProcessUuid($call->receiptId), $call->receiptDisk, $call->receiptUploadKey, new ContentHash($receipt->hash), 1);
        $plan = new ProviderRecoveryPlan($mode, new ProcessUuid($call->authorization->logicalRequestId), new ProcessUuid($call->authorization->physicalCallId),
            self::purpose($call->purpose), $ref, $mapping->expectedWorkInputHash, new ContentHash($call->recipeHash), $call->authorization->requestEpoch);
        if ($pinned !== null && ($pinned->logicalRequestId != $plan->logicalRequestId || $pinned->physicalCallId != $plan->physicalCallId
            || $pinned->purpose !== $plan->purpose || $pinned->expectedInputHash != $plan->expectedInputHash || $pinned->recipeHash != $plan->recipeHash || $pinned->requestEpoch !== $plan->requestEpoch)) {
            throw new ProviderLinkageUnavailable('Pinned provider operation drift.');
        }

        return $plan;
    }

    public function settleLocked(ProviderRecoveryPlan $plan): void
    {
        $mapping = $this->mapping->physical($plan->physicalCallId->value);
        $call = $this->requests->lock($this->requests->get($plan->physicalCallId->value));
        if ($call->authorization->logicalRequestId !== $plan->logicalRequestId->value || $call->authorization->requestEpoch !== $plan->requestEpoch
            || $call->recipeHash !== $plan->recipeHash->hex || $mapping->expectedWorkInputHash->hex !== $plan->expectedInputHash->hex || self::purpose($call->purpose) !== $plan->purpose) {
            throw new \LogicException('Provider settlement identity drift.');
        }
        $receipt = $this->verified[$call->authorization->physicalCallId] ?? null;
        if ($receipt !== null) {
            if ($plan->receipt?->sha256->hex !== $receipt->hash || $plan->receipt->id->value !== $call->receiptId || $plan->receipt->schemaVersion !== 1
                || $plan->receipt->disk !== $call->receiptDisk || $plan->receipt->key !== $call->receiptUploadKey) {
                throw new \LogicException('Provider settlement receipt drift.');
            }
            $this->requests->acceptReceiptLocked($receipt);
        } elseif ($plan->mode === ProviderRecoveryMode::KnownRetryEligible && ! $call->claimed) {
            $this->requests->interruptLocked($call);
        } elseif ($call->outcome !== 'not_sent') {
            throw new \LogicException('Settlement requires verified operation evidence.');
        }
    }

    public function revokeUnclaimedLocked(WorkId $workId): void
    {
        $mapping = $this->mapping->active($workId);
        if ($mapping === null) {
            return;
        }
        $call = $this->requests->lock($this->requests->get($mapping->physicalCallId));
        $receipt = $this->verified[$call->authorization->physicalCallId] ?? null;
        if ($receipt !== null) {
            $this->requests->acceptReceiptLocked($receipt);
        } else {
            $this->requests->interruptLocked($call);
        }
    }

    public function terminalReason(ProviderRecoveryPlan $plan): ?BlockedReason
    {
        $receipt = $this->verified[$plan->physicalCallId->value] ?? null;

        return $receipt?->assessment->outcome === ReceiptOutcome::BilledError ? BlockedReason::PolicyReview : null;
    }

    private static function purpose(OperationPurpose $purpose): ProviderOperationPurpose
    {
        return match ($purpose) {
            OperationPurpose::Generate => ProviderOperationPurpose::Generation,
            OperationPurpose::CacheCreate, OperationPurpose::CacheRefresh, OperationPurpose::CacheStorage => ProviderOperationPurpose::Cache,
            OperationPurpose::FileUpload => ProviderOperationPurpose::File,
            OperationPurpose::Lookup => ProviderOperationPurpose::Lookup,
        };
    }
}
