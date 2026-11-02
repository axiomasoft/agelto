<?php

declare(strict_types=1);

namespace App\Dto\Llm\Requests;

use App\Services\Llm\Schema\CanonicalJson;

/** Output excludes thinking; pricing declares whether thinking is already billed with output. */
final readonly class ProviderUsage
{
    public function __construct(public int $uncachedInput, public int $cachedInput, public int $output,
        public int $thinking, public int $cacheStorageTokenHours, public bool $unknown)
    {
        foreach ([$uncachedInput, $cachedInput, $output, $thinking, $cacheStorageTokenHours] as $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException('Invalid factual provider usage.');
            }
        }
    }

    public function json(): string
    {
        return CanonicalJson::encode(['uncached_input' => $this->uncachedInput, 'cached_input' => $this->cachedInput,
            'output' => $this->output, 'thinking' => $this->thinking, 'cache_storage_token_hours' => $this->cacheStorageTokenHours, 'unknown' => $this->unknown]);
    }
}
