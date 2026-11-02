<?php

declare(strict_types=1);

namespace App\Services\Llm\Execution;

use App\Dto\Llm\Cache\ContextCacheAsset;
use App\Dto\Llm\Cache\ContextCacheKey;
use App\Dto\Llm\Cache\FileAssetKey;
use App\Dto\Llm\Cache\ProviderFileAsset;
use App\Dto\Llm\Requests\ReceiptAssessment;
use App\Dto\Llm\Requests\VerifiedReceipt;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\ReceiptOutcome;
use App\Exceptions\Llm\OperationDenied;
use Illuminate\Container\Attributes\Singleton;

/** Fail closed until the owning provider package explicitly registers its response policy. */
#[Singleton]
final class ReceiptPolicies
{
    /** @var array<string, ReceiptPolicy> */
    private array $policies = [];

    public function register(string $provider, ReceiptPolicy $policy): void
    {
        $this->policies[$provider] = $policy;
    }

    public function assess(string $provider, OperationPurpose $purpose, int $status, string $body): ReceiptAssessment
    {
        return isset($this->policies[$provider])
            ? $this->policies[$provider]->assess($purpose, $status, $body)
            : new ReceiptAssessment(ReceiptOutcome::Unknown, null, 'unregistered_response_policy');
    }

    public function assetMetadata(FileAssetKey|ContextCacheKey $key, VerifiedReceipt $receipt): ProviderFileAsset|ContextCacheAsset
    {
        $policy = $this->policies[$receipt->call->context->provider] ?? null;
        if (! $policy instanceof AssetReceiptPolicy) {
            throw new OperationDenied('asset_metadata_policy_unregistered');
        }

        return $policy->assetMetadata($key, $receipt);
    }
}
