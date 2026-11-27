<?php

declare(strict_types=1);

namespace App\Services\Llm\Providers;

use App\Dto\Llm\Requests\GenerationResponse;
use App\Dto\Llm\Requests\VerifiedReceipt;
use App\Services\Llm\Contracts\ProviderWireCodec;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Llm\Execution\ProviderWireRequest;

final readonly class GeminiWireCodec implements ProviderWireCodec
{
    public function compileGeneration(ProviderOperationPlan $plan, string $origin): ProviderWireRequest
    {
        return (new GeminiGenerateContentClient($origin))->compile($plan);
    }

    public function decodeGeneration(VerifiedReceipt $receipt): GenerationResponse
    {
        return (new GeminiGenerateContentClient)->decode($receipt->body);
    }

    public function isUncachedGenerationBody(string $body): bool
    {
        try {
            $decoded = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        return is_array($decoded) && ! array_key_exists('cachedContent', $decoded);
    }
}
