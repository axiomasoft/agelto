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

    public static function restore(string $json): self
    {
        $value = json_decode($json, associative: true, depth: 8, flags: JSON_THROW_ON_ERROR);
        $keys = ['uncached_input', 'cached_input', 'output', 'thinking', 'cache_storage_token_hours', 'unknown'];
        if (! is_array($value) || count($value) !== count($keys) || array_diff($keys, array_keys($value)) !== []
            || ! is_bool($value['unknown'])) {
            throw new \InvalidArgumentException('Invalid provider usage codec.');
        }
        foreach (array_slice($keys, 0, 5) as $key) {
            if (! is_int($value[$key])) {
                throw new \InvalidArgumentException('Invalid provider usage counter.');
            }
        }

        return new self($value['uncached_input'], $value['cached_input'], $value['output'], $value['thinking'],
            $value['cache_storage_token_hours'], $value['unknown']);
    }
}
