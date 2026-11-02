<?php

declare(strict_types=1);

namespace App\Services\Llm\Execution;

use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Dto\Llm\Requests\PricingSnapshot;
use App\Dto\Llm\Requests\RequestIdentity;

final readonly class ProviderOperationPlan
{
    public function __construct(
        public RequestIdentity $identity,
        public PinnedLlmContext $context,
        public PricingSnapshot $pricing,
        public string $consumerCorrelationId,
        public int $maxPhysicalAttempts = 3,
    ) {
        if ($identity->purpose !== $pricing->purpose || $consumerCorrelationId === '' || $maxPhysicalAttempts < 1 || $maxPhysicalAttempts > 10) {
            throw new \InvalidArgumentException('Invalid operation plan.');
        }
        $maxOutput = $context->options['max_output_tokens'] ?? null;
        if (is_int($maxOutput) && $pricing->outputMaxTokens < $maxOutput) {
            throw new \InvalidArgumentException('Output bound is below the pinned model option.');
        }
    }
}
