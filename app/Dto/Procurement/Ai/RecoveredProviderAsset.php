<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai;

use App\Dto\Llm\Cache\ContextCacheAsset;
use App\Dto\Llm\Cache\ProviderFileAsset;
use App\Dto\Llm\Requests\VerifiedReceipt;

/** Transient evidence; only the stage's normal progress is serialized in the checkpoint. */
final readonly class RecoveredProviderAsset
{
    public function __construct(public VerifiedReceipt $receipt, public ProviderFileAsset|ContextCacheAsset $asset) {}
}
