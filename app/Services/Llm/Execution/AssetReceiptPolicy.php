<?php

declare(strict_types=1);

namespace App\Services\Llm\Execution;

use App\Dto\Llm\Cache\ContextCacheAsset;
use App\Dto\Llm\Cache\ContextCacheKey;
use App\Dto\Llm\Cache\FileAssetKey;
use App\Dto\Llm\Cache\ProviderFileAsset;
use App\Dto\Llm\Requests\VerifiedReceipt;

interface AssetReceiptPolicy extends ReceiptPolicy
{
    public function assetMetadata(FileAssetKey|ContextCacheKey $key, VerifiedReceipt $receipt): ProviderFileAsset|ContextCacheAsset;
}
