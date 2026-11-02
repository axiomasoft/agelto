<?php

declare(strict_types=1);

namespace App\Services\Llm\Execution;

use App\Dto\Llm\Requests\VerifiedReceipt;

/** Provider codec for the exact remote resource named by a successful metadata receipt. */
interface AssetRecoveryPolicy extends AssetReceiptPolicy
{
    public function assetResource(VerifiedReceipt $receipt): string;
}
