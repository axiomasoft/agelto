<?php

declare(strict_types=1);

namespace App\Dto\Llm\Cache;

final readonly class ProviderFileAsset
{
    public function __construct(public FileAssetKey $key, public string $uri, public string $state, public string $expiresAt)
    {
        $url = parse_url($uri);
        if (! is_array($url) || ($url['scheme'] ?? null) !== 'https' || ! isset($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])
            || ! in_array($state, ['PROCESSING', 'ACTIVE', 'FAILED'], true) || strtotime($expiresAt) === false) {
            throw new \InvalidArgumentException('Invalid provider file metadata.');
        }
    }

    public function usable(): bool
    {
        return $this->state === 'ACTIVE' && strtotime($this->expiresAt) > time();
    }
}
