<?php

declare(strict_types=1);

namespace App\Dto\Llm\Cache;

use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Services\Llm\Schema\CanonicalJson;

final readonly class FileAssetKey
{
    public string $hash;

    public function __construct(public PinnedLlmContext $context, public string $contentHash, public string $mime)
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $contentHash) !== 1 || preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~D', $mime) !== 1) {
            throw new \InvalidArgumentException('Invalid immutable file identity.');
        }
        $this->hash = hash('sha256', CanonicalJson::encode([$context->provider, $context->account, $context->apiVersion, $contentHash, $mime]));
    }

    public function assertContext(PinnedLlmContext $context): void
    {
        if ($context->provider !== $this->context->provider || $context->account !== $this->context->account || $context->apiVersion !== $this->context->apiVersion) {
            throw new \InvalidArgumentException('File belongs to a different provider/account/API.');
        }
    }

    /** One replacement identity per frozen consumer run, including after another delivery. */
    public function replacementInputHash(): string
    {
        return hash('sha256', $this->hash.':replacement');
    }
}
