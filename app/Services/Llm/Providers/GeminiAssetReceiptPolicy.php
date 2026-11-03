<?php

declare(strict_types=1);

namespace App\Services\Llm\Providers;

use App\Dto\Llm\Cache\ContextCacheAsset;
use App\Dto\Llm\Cache\ContextCacheKey;
use App\Dto\Llm\Cache\FileAssetKey;
use App\Dto\Llm\Cache\ProviderFileAsset;
use App\Dto\Llm\Requests\ProviderUsage;
use App\Dto\Llm\Requests\ReceiptAssessment;
use App\Dto\Llm\Requests\VerifiedReceipt;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\ReceiptOutcome;
use App\Exceptions\Llm\OperationDenied;
use App\Services\Llm\Execution\AssetRecoveryPolicy;

/** No HTTP status, including 404, proves that a generation/cache create was not billed. */
final readonly class GeminiAssetReceiptPolicy implements AssetRecoveryPolicy
{
    public function assetResource(VerifiedReceipt $receipt): string
    {
        $value = json_decode($receipt->body, associative: true, depth: 64, flags: JSON_THROW_ON_ERROR);
        $metadata = is_array($value) && $receipt->call->purpose === OperationPurpose::FileUpload ? ($value['file'] ?? null) : $value;
        if (! is_array($metadata) || ! is_string($metadata['name'] ?? null)) {
            throw new OperationDenied('asset_metadata_invalid');
        }
        $resource = str_starts_with($metadata['name'], 'files/') ? ($metadata['uri'] ?? null) : $metadata['name'];
        if (! is_string($resource) || $resource === '') {
            throw new OperationDenied('asset_metadata_invalid');
        }

        return $resource;
    }

    public function assess(OperationPurpose $purpose, int $httpStatus, string $body): ReceiptAssessment
    {
        $unknown = new ReceiptAssessment(ReceiptOutcome::Unknown, null, 'gemini-assets/1:charge_unproven',
            new ProviderUsage(0, 0, 0, 0, 0, true));
        try {
            $value = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $unknown;
        }
        if (! is_array($value) || array_key_exists('error', $value) || $httpStatus !== 200 || $purpose === OperationPurpose::CacheStorage) {
            return $unknown;
        }
        if ($purpose === OperationPurpose::Generate) {
            $candidates = $value['candidates'] ?? null;
            $usage = $value['usageMetadata'] ?? null;
            $feedback = $value['promptFeedback'] ?? null;
            if (! is_array($candidates) && ! (is_array($feedback) && is_string($feedback['blockReason'] ?? null))) {
                return $unknown;
            }
            $model = self::provenance($value['modelVersion'] ?? null);
            $candidate = is_array($candidates) ? ($candidates[0] ?? null) : null;
            $finish = self::provenance(is_array($candidate) ? ($candidate['finishReason'] ?? null) : null);
            // A verified generation envelope is a financial fact even when its output is refused,
            // empty or unsupported. The domain policy must see it, with the original bound retained.
            if (! is_array($usage)) {
                return new ReceiptAssessment(ReceiptOutcome::Success, null, 'gemini-assets/1:generation_unknown_usage_conservative_bound',
                    new ProviderUsage(0, 0, 0, 0, 0, true), $model, $finish);
            }
            $prompt = $usage['promptTokenCount'] ?? null;
            $cached = $usage['cachedContentTokenCount'] ?? 0;
            $output = $usage['candidatesTokenCount'] ?? null;
            $thinking = $usage['thoughtsTokenCount'] ?? 0;
            foreach ([$prompt, $cached, $output, $thinking] as $tokens) {
                if (! is_int($tokens) || $tokens < 0 || $tokens > 1_000_000_000) {
                    return $unknown;
                }
            }
            if (! is_int($prompt) || ! is_int($cached) || ! is_int($output) || ! is_int($thinking) || $cached > $prompt) {
                return $unknown;
            }

            return new ReceiptAssessment(ReceiptOutcome::Success, null, 'gemini-assets/1:generation_conservative_bound',
                new ProviderUsage($prompt - $cached, $cached, $output, $thinking, 0, false), $model, $finish);
        }
        $metadata = $purpose === OperationPurpose::FileUpload ? ($value['file'] ?? null) : $value;
        if (! is_array($metadata) || ! is_string($metadata['name'] ?? null)) {
            return $unknown;
        }
        $file = str_starts_with($metadata['name'], 'files/');
        if ($purpose === OperationPurpose::FileUpload && ! $file
            || in_array($purpose, [OperationPurpose::CacheCreate, OperationPurpose::CacheRefresh], true) && $file) {
            return $unknown;
        }
        if ($file && (! is_string($metadata['uri'] ?? null) || ! is_string($metadata['expirationTime'] ?? null)
                || ! in_array($metadata['state'] ?? null, ['ACTIVE', 'PROCESSING', 'FAILED'], true))
            || ! $file && (! str_starts_with($metadata['name'], 'cachedContents/') || ! is_string($metadata['expireTime'] ?? null)
                || ! is_string($metadata['model'] ?? null))) {
            return $unknown;
        }

        // Cost is conservatively settled to the pinned operation bound, including the whole cache TTL.
        $tokenHours = 0;
        $storageKnown = $file || $purpose === OperationPurpose::Lookup;
        // A lookup creates no storage. Refresh metadata reports lifetime since creation,
        // not the increment attributable to this refresh; retain explicitly unknown usage.
        if ($purpose === OperationPurpose::CacheCreate && is_array($metadata['usageMetadata'] ?? null) && is_int($metadata['usageMetadata']['totalTokenCount'] ?? null)
            && $metadata['usageMetadata']['totalTokenCount'] >= 0 && $metadata['usageMetadata']['totalTokenCount'] <= 1_000_000_000
            && is_string($metadata['createTime'] ?? null) && strtotime($metadata['createTime']) !== false && strtotime($metadata['expireTime']) !== false) {
            $seconds = strtotime($metadata['expireTime']) - strtotime($metadata['createTime']);
            if ($seconds > 0 && $seconds <= 172800) {
                $tokenHours = intdiv($metadata['usageMetadata']['totalTokenCount'] * $seconds + 3599, 3600);
                $storageKnown = true;
            }
        }

        return new ReceiptAssessment(ReceiptOutcome::Success, null, 'gemini-assets/1:successful_metadata_conservative_bound',
            new ProviderUsage(0, 0, 0, 0, $tokenHours, ! $storageKnown));
    }

    private static function provenance(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._\/:+-]{0,127}$/D', $value) === 1 ? $value : null;
    }

    public function assetMetadata(FileAssetKey|ContextCacheKey $key, VerifiedReceipt $receipt): ProviderFileAsset|ContextCacheAsset
    {
        $value = json_decode($receipt->body, associative: true, flags: JSON_THROW_ON_ERROR);
        $metadata = $key instanceof FileAssetKey && $receipt->call->purpose === OperationPurpose::FileUpload
            ? (is_array($value) ? ($value['file'] ?? null) : null) : $value;
        if (! is_array($metadata)) {
            throw new OperationDenied('asset_metadata_invalid');
        }
        if ($key instanceof FileAssetKey) {
            if (($metadata['mimeType'] ?? null) !== $key->mime || ! is_string($metadata['uri'] ?? null)
                || preg_match('~^https://generativelanguage\.googleapis\.com/v1beta/files/[a-z0-9-]+$~D', $metadata['uri']) !== 1
                || ! is_string($metadata['state'] ?? null) || ! is_string($metadata['expirationTime'] ?? null)) {
                throw new OperationDenied('file_metadata_invalid');
            }

            return new ProviderFileAsset($key, $metadata['uri'], $metadata['state'], $metadata['expirationTime']);
        }
        if (($metadata['model'] ?? null) !== 'models/'.$key->context->model
            || ! is_string($metadata['name'] ?? null) || preg_match('~^cachedContents/[a-zA-Z0-9_-]+$~D', $metadata['name']) !== 1
            || ! is_string($metadata['expireTime'] ?? null)) {
            throw new OperationDenied('cache_metadata_invalid');
        }

        return new ContextCacheAsset($key, $metadata['name'], $metadata['expireTime']);
    }
}
