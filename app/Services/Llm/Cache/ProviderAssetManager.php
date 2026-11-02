<?php

declare(strict_types=1);

namespace App\Services\Llm\Cache;

use App\Dto\Llm\Cache\ContextCacheAsset;
use App\Dto\Llm\Cache\ContextCacheKey;
use App\Dto\Llm\Cache\FileAssetKey;
use App\Dto\Llm\Cache\ProviderFileAsset;
use App\Dto\Llm\Requests\VerifiedReceipt;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\ReceiptOutcome;
use App\Exceptions\Llm\OperationDenied;
use App\Repositories\Llm\Cache\AssetRepository;
use App\Repositories\Llm\Request\FinancialReservationRepository;
use App\Repositories\Llm\Request\RequestRepository;
use App\Services\Llm\Execution\ProviderWireRequest;
use App\Services\Llm\Execution\ReceiptPolicies;
use Illuminate\Support\Facades\DB;

/** No generation parser, download, send, retry or TTL scheduler. Receipt bytes are verified by ReceiptStore. */
final readonly class ProviderAssetManager
{
    public function __construct(private AssetRepository $assets, private RequestRepository $requests, private ReceiptPolicies $policies) {}

    public function claim(FileAssetKey|ContextCacheKey $key, string $physicalCallId, ProviderWireRequest $wire): void
    {
        DB::transaction(function () use ($key, $physicalCallId, $wire): void {
            $call = $this->requests->get($physicalCallId);
            if ($call->wireFingerprint !== $wire->fingerprint || $call->context != $wire->operation->context || $wire->assetKey != $key) {
                throw new OperationDenied('sealed_wire_conflict');
            }
            $this->assets->claim($key, $call);
        });
    }

    public function restore(FileAssetKey|ContextCacheKey $key, VerifiedReceipt $receipt): ProviderFileAsset|ContextCacheAsset
    {
        if ($receipt->assessment->outcome !== ReceiptOutcome::Success || $receipt->status !== 200
            || $receipt->call->purpose === OperationPurpose::Generate) {
            throw new OperationDenied('successful_asset_receipt_required');
        }
        $asset = $this->policies->assetMetadata($key, $receipt);
        DB::transaction(fn () => $this->restoreLocked($asset, $receipt));

        return $asset;
    }

    /** Pure metadata preparation; the business finalizer owns the later mutation. */
    public function prepareRecovery(VerifiedReceipt $receipt): ProviderFileAsset|ContextCacheAsset
    {
        if ($receipt->assessment->outcome !== ReceiptOutcome::Success || $receipt->status !== 200
            || ! in_array($receipt->call->purpose, [OperationPurpose::FileUpload, OperationPurpose::CacheCreate, OperationPurpose::CacheRefresh, OperationPurpose::Lookup], true)) {
            throw new OperationDenied('successful_asset_receipt_required');
        }
        $key = $this->assets->recoveryKey($receipt->call, $this->policies->assetResource($receipt));

        return $this->policies->assetMetadata($key, $receipt);
    }

    public function restoreLocked(ProviderFileAsset|ContextCacheAsset $asset, VerifiedReceipt $receipt): void
    {
        FinancialReservationRepository::requireTransaction();
        if ($this->prepareRecovery($receipt) != $asset) {
            throw new OperationDenied('asset_recovery_metadata_drift');
        }
        $call = $this->requests->lock($receipt->call);
        $this->requests->acceptReceiptLocked($receipt);
        $this->assets->restore($asset, $call);
    }

    public function invalidateMissing(FileAssetKey|ContextCacheKey $key, ProviderWireRequest $wire, VerifiedReceipt $receipt): void
    {
        if ($receipt->call->purpose !== OperationPurpose::Lookup || $receipt->status !== 404
            || $receipt->assessment->outcome !== ReceiptOutcome::NonbillableRetry || $wire->assetKey != $key
            || $receipt->call->wireFingerprint !== $wire->fingerprint) {
            throw new OperationDenied('known_nonbillable_asset_lookup_required');
        }
        DB::transaction(function () use ($key, $receipt, $wire): void {
            $call = $this->requests->lock($receipt->call);
            $asset = $key instanceof FileAssetKey ? $this->assets->findFile($key) : $this->assets->findCache($key);
            $resource = $asset instanceof ProviderFileAsset ? basename($asset->uri) : ($asset?->name);
            $path = parse_url($wire->endpoint, PHP_URL_PATH);
            if (! is_string($resource) || ! is_string($path) || ! str_ends_with($path, '/'.$resource)) {
                throw new OperationDenied('asset_lookup_identity_drift');
            }
            $this->requests->acceptReceiptLocked($receipt);
            $this->assets->expire($key, $call);
        });
    }
}
