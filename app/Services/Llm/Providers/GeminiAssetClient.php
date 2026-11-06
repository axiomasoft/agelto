<?php

declare(strict_types=1);

namespace App\Services\Llm\Providers;

use App\Dto\Llm\Cache\CachePrefix;
use App\Dto\Llm\Cache\ContextCacheAsset;
use App\Dto\Llm\Cache\ContextCacheKey;
use App\Dto\Llm\Cache\FileAssetKey;
use App\Dto\Llm\Cache\ProviderCapabilities;
use App\Enums\Llm\OperationPurpose;
use App\Exceptions\Llm\OperationDenied;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Llm\Execution\ProviderWireRequest;
use App\Services\Llm\Schema\CanonicalJson;

/** Pure REST codec; every returned wire needs its own financial and joint network claim. */
final readonly class GeminiAssetClient
{
    public function __construct(private string $origin = 'https://generativelanguage.googleapis.com') {}

    public function upload(ProviderOperationPlan $plan, ProviderCapabilities $capabilities, FileAssetKey $key, string $bytes): ProviderWireRequest
    {
        $this->assertPlan($plan, $capabilities, OperationPurpose::FileUpload);
        $key->assertContext($plan->context);
        if (! $capabilities->files || $bytes === '' || strlen($bytes) > $capabilities->maximumFileBytes || hash('sha256', $bytes) !== $key->contentHash
            || ! in_array($plan->identity->inputHash, [$key->hash, $key->replacementInputHash()], true)) {
            throw new OperationDenied('immutable_file_or_capability_mismatch');
        }
        $boundary = 'agelto-'.hash('sha256', $bytes.$key->mime);
        if (str_contains($bytes, $boundary)) {
            throw new OperationDenied('multipart_boundary_conflict');
        }
        $body = '--'.$boundary."\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n"
            .CanonicalJson::encode(['file' => ['displayName' => $key->contentHash]])
            ."\r\n--".$boundary."\r\nContent-Type: ".$key->mime."\r\n\r\n".$bytes."\r\n--".$boundary."--\r\n";

        return new ProviderWireRequest($plan, 'POST', $this->origin.'/upload/v1beta/files', $this->origin, $body,
            'multipart/related; boundary='.$boundary, ['X-Goog-Upload-Protocol' => 'multipart'], $key, $capabilities);
    }

    /** Unknown reuse, ineligible size or unavailable explicit cache selects the implicit path before creating any intent. */
    public function createCache(ProviderOperationPlan $plan, ProviderCapabilities $capabilities, CachePrefix $prefix,
        int $tokens, ?int $expectedReuses, int $savedMicrosPerReuse, int $ttlSeconds): ?ProviderWireRequest
    {
        $this->assertPlan($plan, $capabilities, OperationPurpose::CacheCreate);
        if ($prefix->key->context != $plan->context || $tokens < 0 || $savedMicrosPerReuse < 0) {
            throw new OperationDenied('cache_identity_drift');
        }
        if (! $capabilities->explicitCache || $tokens < $capabilities->minimumCacheTokens || $expectedReuses === null || $expectedReuses < 1) {
            return null;
        }
        $this->assertTtl($plan, $capabilities, $tokens, $ttlSeconds);
        if (bccomp(bcmul((string) $expectedReuses, (string) $savedMicrosPerReuse), bcmul($plan->pricing->upperBound, '1000000', 0)) <= 0) {
            return null;
        }
        foreach ($prefix->files as $file) {
            if (! $file->usable() || strtotime($file->expiresAt) < time() + $ttlSeconds) {
                throw new OperationDenied('file_asset_unavailable');
            }
        }

        return new ProviderWireRequest($plan, 'POST', $this->origin.'/v1beta/cachedContents', $this->origin,
            CanonicalJson::encode(['model' => 'models/'.$plan->context->model, 'ttl' => $ttlSeconds.'s',
                'systemInstruction' => ['parts' => [['text' => $prefix->system->content]]],
                'contents' => [['role' => 'user', 'parts' => GeminiGenerateContentClient::fileParts($prefix)]]]), assetKey: $prefix->key, capabilities: $capabilities);
    }

    /** Explicit caller operation only. There is no automatic TTL refresh. */
    public function refreshCache(ProviderOperationPlan $plan, ProviderCapabilities $capabilities, ContextCacheAsset $cache,
        int $tokens, int $ttlSeconds): ProviderWireRequest
    {
        $this->assertPlan($plan, $capabilities, OperationPurpose::CacheRefresh);
        if (! $capabilities->explicitCache || $cache->key->context != $plan->context || preg_match('~^cachedContents/[a-zA-Z0-9_-]+$~D', $cache->name) !== 1) {
            throw new OperationDenied('cache_identity_drift');
        }
        $this->assertTtl($plan, $capabilities, $tokens, $ttlSeconds);

        return new ProviderWireRequest($plan, 'PATCH', $this->origin.'/v1beta/'.$cache->name.'?updateMask=ttl', $this->origin,
            CanonicalJson::encode(['ttl' => $ttlSeconds.'s']), assetKey: $cache->key, capabilities: $capabilities);
    }

    public function lookup(ProviderOperationPlan $plan, ProviderCapabilities $capabilities, FileAssetKey|ContextCacheKey $key, string $resource): ProviderWireRequest
    {
        $this->assertPlan($plan, $capabilities, OperationPurpose::Lookup);
        if (preg_match('~^(files/[a-z0-9-]+|cachedContents/[a-zA-Z0-9_-]+)$~D', $resource) !== 1
            || str_starts_with($resource, 'files/') && ! $capabilities->files
            || str_starts_with($resource, 'cachedContents/') && ! $capabilities->explicitCache) {
            throw new OperationDenied('asset_lookup_unsupported');
        }

        if ($key instanceof FileAssetKey) {
            $key->assertContext($plan->context);
        }
        if ($plan->identity->inputHash !== $key->hash || $key instanceof ContextCacheKey && $key->context != $plan->context) {
            throw new OperationDenied('asset_lookup_identity_drift');
        }

        return new ProviderWireRequest($plan, 'GET', $this->origin.'/v1beta/'.$resource, $this->origin, '', assetKey: $key, capabilities: $capabilities);
    }

    private function assertPlan(ProviderOperationPlan $plan, ProviderCapabilities $capabilities, OperationPurpose $purpose): void
    {
        $capabilities->assertContext($plan->context);
        if ($plan->identity->purpose !== $purpose || $plan->generation !== null || strtotime($plan->pricing->validUntil) <= time()) {
            throw new OperationDenied('asset_operation_configuration_hold');
        }
    }

    private function assertTtl(ProviderOperationPlan $plan, ProviderCapabilities $capabilities, int $tokens, int $ttl): void
    {
        $hours = bcdiv(bcadd(bcmul((string) $tokens, (string) $ttl), '3599'), '3600', 0);
        if ($tokens < 1 || $ttl < 1 || $ttl > $capabilities->maximumTtlSeconds
            || bccomp((string) $plan->pricing->storageTokenHoursMax, $hours) < 0) {
            throw new OperationDenied('cache_ttl_not_reserved');
        }
    }
}
