<?php

declare(strict_types=1);

namespace App\Dto\Llm\Cache;

use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Exceptions\Llm\OperationDenied;

/** Explicit, account-scoped catalog snapshot. No inference from a provider/model name. */
final readonly class ProviderCapabilities
{
    public function __construct(
        public PinnedLlmContext $context,
        public string $apiSurface,
        public string $version,
        public string $validUntil,
        public bool $files,
        public bool $explicitCache,
        public bool $implicitCache,
        public int $minimumCacheTokens,
        public int $maximumFileBytes,
        public int $maximumTtlSeconds,
        public string $evidence,
    ) {
        if ($version === '' || $evidence === '' || strtotime($validUntil) === false
            || $minimumCacheTokens < 1 || $maximumFileBytes < 1 || $maximumFileBytes > 8_388_608
            || $maximumTtlSeconds < 1 || $maximumTtlSeconds > 172800) {
            throw new OperationDenied('capability_configuration_hold');
        }
    }

    public function assertContext(PinnedLlmContext $context): void
    {
        if ($context != $this->context || $this->apiSurface !== 'generateContent'
            || $context->apiVersion !== 'v1beta' || strtotime($this->validUntil) <= time()) {
            throw new OperationDenied('capability_configuration_hold');
        }
    }
}
