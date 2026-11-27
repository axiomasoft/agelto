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
        if (in_array($httpStatus, [429, 503], true) || ($httpStatus === 404 && $purpose === OperationPurpose::Lookup)) {
            return self::nonbillable(ReceiptOutcome::NonbillableRetry, $httpStatus);
        }
        $unknown = new ReceiptAssessment(outcome: ReceiptOutcome::Unknown, cost: null, policyEvidence: 'gemini-assets/2:charge_unproven',
            usage: new ProviderUsage(uncachedInput: 0, cachedInput: 0, output: 0, thinking: 0, cacheStorageTokenHours: 0, unknown: true));
        try {
            $value = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $unknown;
        }
        if (! is_array($value) || $purpose === OperationPurpose::CacheStorage) {
            return $unknown;
        }
        if ($httpStatus !== 200) {
            return self::assessError(purpose: $purpose, status: $httpStatus, value: $value) ?? $unknown;
        }
        if (array_key_exists('error', $value)) {
            return $unknown;
        }
        if ($purpose === OperationPurpose::Generate) {
            $candidates = $value['candidates'] ?? null;
            $feedback = $value['promptFeedback'] ?? null;
            $blockReason = is_array($feedback) ? self::provenance($feedback['blockReason'] ?? null) : null;
            $blocked = $blockReason !== null && $blockReason !== 'BLOCK_REASON_UNSPECIFIED';
            if (! is_array($candidates) && ! $blocked) {
                return $unknown;
            }
            $model = self::provenance($value['modelVersion'] ?? null);
            $candidate = is_array($candidates) ? ($candidates[0] ?? null) : null;
            $finish = self::provenance(is_array($candidate) ? ($candidate['finishReason'] ?? null) : null) ?? $blockReason;
            $rawUsage = $value['usageMetadata'] ?? null;
            // A verified generation envelope still reaches the domain policy when usage was omitted.
            if ($rawUsage === null) {
                return new ReceiptAssessment(outcome: ReceiptOutcome::Success, cost: null, policyEvidence: 'gemini-assets/2:generation_unknown_usage_conservative_bound',
                    usage: $unknown->usage, resolvedModelVersion: $model, finishReason: $finish);
            }
            $usage = self::generationUsage($rawUsage, blocked: $blocked);

            return $usage === null ? $unknown : new ReceiptAssessment(outcome: ReceiptOutcome::Success, cost: null,
                policyEvidence: 'gemini-assets/2:generation_reported_usage', usage: $usage, resolvedModelVersion: $model, finishReason: $finish);
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

        return new ReceiptAssessment(outcome: ReceiptOutcome::Success, cost: null,
            policyEvidence: $storageKnown ? 'gemini-assets/2:asset_reported_usage' : 'gemini-assets/2:asset_unknown_usage_conservative_bound',
            usage: new ProviderUsage(uncachedInput: 0, cachedInput: 0, output: 0, thinking: 0, cacheStorageTokenHours: $tokenHours, unknown: ! $storageKnown));
    }

    private static function nonbillable(ReceiptOutcome $outcome, int $status): ReceiptAssessment
    {
        return new ReceiptAssessment(outcome: $outcome, cost: '0', policyEvidence: "gemini-assets/2:http_{$status}_{$outcome->value}",
            usage: new ProviderUsage(uncachedInput: 0, cachedInput: 0, output: 0, thinking: 0, cacheStorageTokenHours: 0, unknown: false));
    }

    /** @param array<array-key, mixed> $value */
    private static function assessError(OperationPurpose $purpose, int $status, array $value): ?ReceiptAssessment
    {
        $error = $value['error'] ?? null;
        if ($status < 400 || $status >= 500 || ! is_array($error) || ($error['code'] ?? null) !== $status) {
            return null;
        }
        if ($purpose === OperationPurpose::Generate && array_key_exists('usageMetadata', $value)) {
            $usage = self::generationUsage($value['usageMetadata']);

            return $usage === null ? null : new ReceiptAssessment(outcome: ReceiptOutcome::BilledError, cost: null,
                policyEvidence: "gemini-assets/2:http_{$status}_reported_usage", usage: $usage);
        }
        $message = $error['message'] ?? null;
        if ($status === 404 && $purpose === OperationPurpose::Generate && is_string($message)
            && preg_match('~\bcached\s*contents?(?:/[a-z0-9_-]+)?[\x22\x27]?\s+(?:(?:was|is)\s+)?(?:not found|does not exist)\b~i', $message) === 1) {
            return self::nonbillable(ReceiptOutcome::NonbillableCacheMiss, $status);
        }

        return in_array($status, [400, 401, 402, 403], true) ? self::nonbillable(ReceiptOutcome::NonbillableRetry, $status) : null;
    }

    private static function generationUsage(mixed $value, bool $blocked = false): ?ProviderUsage
    {
        if (! is_array($value)) {
            return null;
        }
        $prompt = $value['promptTokenCount'] ?? null;
        $cached = $value['cachedContentTokenCount'] ?? 0;
        $output = array_key_exists('candidatesTokenCount', $value) ? $value['candidatesTokenCount'] : ($blocked ? 0 : null);
        $thinking = $value['thoughtsTokenCount'] ?? 0;
        if (! is_int($prompt) || ! is_int($cached) || ! is_int($output) || ! is_int($thinking) || $cached > $prompt
            || min($prompt, $cached, $output, $thinking) < 0 || max($prompt, $cached, $output, $thinking) > 1_000_000_000) {
            return null;
        }

        return new ProviderUsage(uncachedInput: $prompt - $cached, cachedInput: $cached, output: $output, thinking: $thinking,
            cacheStorageTokenHours: 0, unknown: false);
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
