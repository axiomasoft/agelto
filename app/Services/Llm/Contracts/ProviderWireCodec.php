<?php

declare(strict_types=1);

namespace App\Services\Llm\Contracts;

use App\Dto\Llm\Requests\GenerationResponse;
use App\Dto\Llm\Requests\VerifiedReceipt;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Llm\Execution\ProviderWireRequest;

interface ProviderWireCodec
{
    public function compileGeneration(ProviderOperationPlan $plan, string $origin): ProviderWireRequest;

    public function decodeGeneration(VerifiedReceipt $receipt): GenerationResponse;

    public function isUncachedGenerationBody(string $body): bool;
}
