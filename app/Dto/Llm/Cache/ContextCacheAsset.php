<?php

declare(strict_types=1);

namespace App\Dto\Llm\Cache;

final readonly class ContextCacheAsset
{
    public function __construct(public ContextCacheKey $key, public string $name, public string $expiresAt)
    {
        if ($name === '' || strlen($name) > 512 || preg_match('~^[a-zA-Z0-9_/-]+$~D', $name) !== 1 || strtotime($expiresAt) === false) {
            throw new \InvalidArgumentException('Invalid context cache metadata.');
        }
    }

    public function usable(): bool
    {
        return strtotime($this->expiresAt) > time();
    }
}
