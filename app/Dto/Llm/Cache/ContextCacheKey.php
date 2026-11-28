<?php

declare(strict_types=1);

namespace App\Dto\Llm\Cache;

use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Services\Llm\Schema\CanonicalJson;

final readonly class ContextCacheKey
{
    public string $hash;

    public function __construct(public PinnedLlmContext $context, public string $systemHash, public string $schemaToolPolicyHash,
        public string $representationHash, public string $contentOrderingHash)
    {
        foreach ([$systemHash, $schemaToolPolicyHash, $representationHash, $contentOrderingHash] as $hash) {
            if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new \InvalidArgumentException('Invalid immutable cache identity.');
            }
        }
        $this->hash = hash('sha256', CanonicalJson::encode([$context->provider, $context->account, $context->apiVersion,
            $context->model, $systemHash, $schemaToolPolicyHash, $representationHash, $contentOrderingHash]));
    }
}
