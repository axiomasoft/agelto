<?php

declare(strict_types=1);

namespace App\Services\Llm\Execution;

use App\Dto\Llm\Cache\ContextCacheKey;
use App\Dto\Llm\Cache\FileAssetKey;
use App\Dto\Llm\Cache\ProviderCapabilities;
use App\Enums\Llm\OperationPurpose;
use App\Services\Llm\Schema\CanonicalJson;

/** Pure immutable wire, without credentials or any authority to send it. */
final readonly class ProviderWireRequest
{
    public string $bodyHash;

    public string $fingerprint;

    /** @param array<string, string> $headers */
    public function __construct(
        public ProviderOperationPlan $operation,
        public string $method,
        public string $endpoint,
        public string $allowedOrigin,
        public string $body,
        public string $contentType = 'application/json',
        public array $headers = [],
        public FileAssetKey|ContextCacheKey|null $assetKey = null,
        public ?ProviderCapabilities $capabilities = null,
    ) {
        $url = parse_url($endpoint);
        $origin = parse_url($allowedOrigin);
        if (! in_array($method, ['POST', 'GET', 'PATCH', 'DELETE'], true) || ! is_array($url) || ! is_array($origin)
            || ! isset($url['scheme'], $url['host']) || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) && $url['query'] !== 'updateMask=ttl' || isset($url['fragment'])
            || array_diff_key($origin, array_flip(['scheme', 'host', 'port'])) !== []
            || $url['scheme'] !== ($origin['scheme'] ?? null) || $url['host'] !== ($origin['host'] ?? null)
            || ($url['port'] ?? null) !== ($origin['port'] ?? null)
            || ! in_array($url['scheme'], ['http', 'https'], true) || strlen($body) > 8_650_752) {
            throw new \InvalidArgumentException('Invalid sealed wire origin/method/body.');
        }
        if ($contentType === 'application/json') {
            if ($body !== '') {
                json_decode($body, flags: JSON_THROW_ON_ERROR);
            } elseif (! in_array($method, ['GET', 'DELETE'], true)) {
                throw new \InvalidArgumentException('Missing sealed JSON body.');
            }
            if ($headers !== []) {
                throw new \InvalidArgumentException('Unsupported JSON wire headers.');
            }
        } elseif ($operation->identity->purpose !== OperationPurpose::FileUpload || $method !== 'POST'
            || preg_match('/^multipart\/related; boundary=agelto-[a-f0-9]{64}$/D', $contentType) !== 1
            || $headers !== ['X-Goog-Upload-Protocol' => 'multipart']) {
            throw new \InvalidArgumentException('Unsupported sealed media wire.');
        }
        $this->bodyHash = hash('sha256', $body);
        $this->fingerprint = hash('sha256', CanonicalJson::encode([
            'logical_key' => $operation->identity->logicalKey, 'context' => $operation->context->json,
            'purpose' => $operation->identity->purpose->value, 'task_purpose' => $operation->generation?->purpose->value, 'input' => $operation->identity->inputHash,
            'method' => $method, 'endpoint' => $endpoint, 'origin' => $allowedOrigin, 'body' => $this->bodyHash,
            'content_type' => $contentType, 'headers' => $headers,
            'asset_key' => $assetKey?->hash,
            'capabilities' => $capabilities === null ? null : [$capabilities->version, $capabilities->validUntil,
                $capabilities->files, $capabilities->explicitCache, $capabilities->implicitCache,
                $capabilities->minimumCacheTokens, $capabilities->maximumFileBytes, $capabilities->maximumTtlSeconds,
                $capabilities->apiSurface, $capabilities->evidence],
        ]));
    }

    public function assertOperation(ProviderOperationPlan $plan): void
    {
        if ($plan != $this->operation) {
            throw new \InvalidArgumentException('Wire does not belong to the pinned operation.');
        }
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['fingerprint' => $this->fingerprint];
    }
}
