<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Procurement\Ai\AnalysisRunSnapshot;
use App\Dto\Procurement\Ai\RecoveredProviderAsset;
use App\Dto\Procurement\Process\ExecutionContext;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\ProviderRecoveryPlan;
use App\Dto\Procurement\Process\WorkId;
use App\Dto\Procurement\Process\WorkInput;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Procurement\Process\ProviderOperationPurpose;
use App\Enums\Procurement\Process\ProviderRecoveryMode;
use App\Exceptions\Llm\OperationDenied;
use App\Repositories\Llm\Request\RequestRepository;
use App\Repositories\Procurement\Ai\AnalysisRequestRepository;
use App\Repositories\Procurement\Process\CallerTransaction;
use App\Repositories\Procurement\Process\WorkStoreRepository;
use App\Services\Llm\Cache\ProviderAssetManager;
use App\Services\Llm\Execution\ReceiptStore;
use App\Services\Procurement\Process\ProcessPayloadCodec;

/** Asset recovery is a progress commit, never a model response or a new provider authorization. */
final readonly class AnalysisAssetRecovery
{
    public function __construct(private AnalysisRequestRepository $mapping, private RequestRepository $requests,
        private ReceiptStore $receipts, private ProviderAssetManager $assets) {}

    public function inspect(AnalysisRunSnapshot $run, ExecutionContext $context): ?RecoveredProviderAsset
    {
        $lease = $context->lease();
        $plan = $lease->checkpoint?->providerRecoveryPlan;
        if (! $plan instanceof ProviderRecoveryPlan || $plan->mode !== ProviderRecoveryMode::ResumeProviderAsset) {
            return null;
        }
        $mapping = $this->mapping->active($lease->workId);
        if ($mapping === null || $mapping->string('physical_call_id') !== $plan->physicalCallId->value
            || $mapping->string('logical_request_id') !== $plan->logicalRequestId->value || $mapping->string('recipe_hex') !== $run->recipe->hash->hex
            || $mapping->string('analysis_run_id') !== $run->id->value || $mapping->string('input_hex') !== $lease->inputHash->hex
            || $plan->recipeHash != $run->recipe->hash || $plan->expectedInputHash != $lease->inputHash) {
            throw new OperationDenied('asset_recovery_linkage_drift');
        }
        $call = $this->requests->get($plan->physicalCallId->value);
        $purpose = match ($call->purpose) {
            OperationPurpose::FileUpload => ProviderOperationPurpose::File,
            OperationPurpose::CacheCreate, OperationPurpose::CacheRefresh, OperationPurpose::CacheStorage => ProviderOperationPurpose::Cache,
            OperationPurpose::Lookup => ProviderOperationPurpose::Lookup,
            OperationPurpose::Generate => ProviderOperationPurpose::Generation,
        };
        $receipt = $this->receipts->inspect($call);
        if ($receipt === null || $call->authorization->logicalRequestId !== $plan->logicalRequestId->value
            || $call->authorization->requestEpoch !== $plan->requestEpoch || $plan->purpose !== $purpose || $call->context != $run->recipe->llm
            || $call->recipeHash !== $run->recipe->hash->hex || $plan->receipt?->sha256->hex !== $receipt->hash
            || $plan->receipt->id->value !== $call->receiptId || $plan->receipt->disk !== $call->receiptDisk
            || $plan->receipt->key !== $call->receiptUploadKey || $plan->receipt->schemaVersion !== 1) {
            throw new OperationDenied('pinned_asset_receipt_drift');
        }

        return new RecoveredProviderAsset($receipt, $this->assets->prepareRecovery($receipt));
    }

    public function storeLocked(WorkInput $input, AnalysisRunSnapshot $run, RecoveredProviderAsset $recovery): void
    {
        CallerTransaction::require('Analysis asset recovery progress');
        $call = $recovery->receipt->call;
        $mapping = $this->mapping->physical($call->authorization->physicalCallId);
        $inputHash = WorkStoreRepository::inputHash((new ProcessPayloadCodec)->encodeInput($input)->json ?? '');
        if ($mapping->string('analysis_run_id') !== $run->id->value || $mapping->string('input_hex') !== $inputHash->hex
            || $mapping->string('recipe_hex') !== $run->recipe->hash->hex || $call->recipeHash !== $run->recipe->hash->hex
            || $call->context != $run->recipe->llm) {
            throw new OperationDenied('asset_recovery_linkage_drift');
        }
        $this->assets->restoreLocked($recovery->asset, $recovery->receipt);
        $this->mapping->consumeLocked(new WorkId(new ProcessUuid($mapping->string('work_id'))), $call->authorization->physicalCallId);
    }
}
