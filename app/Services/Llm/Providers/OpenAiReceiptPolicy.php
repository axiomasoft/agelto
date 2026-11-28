<?php

declare(strict_types=1);

namespace App\Services\Llm\Providers;

use App\Dto\Llm\Requests\ProviderUsage;
use App\Dto\Llm\Requests\ReceiptAssessment;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\ReceiptOutcome;
use App\Services\Llm\Execution\ReceiptPolicy;

final readonly class OpenAiReceiptPolicy implements ReceiptPolicy
{
    public function assess(OperationPurpose $purpose, int $httpStatus, string $body): ReceiptAssessment
    {
        if (in_array($httpStatus, [429, 502, 503, 504], true)) {
            return self::nonbillable($httpStatus);
        }
        $unknown = new ReceiptAssessment(
            outcome: ReceiptOutcome::Unknown,
            cost: null,
            policyEvidence: 'openai/1:charge_unproven',
            usage: new ProviderUsage(uncachedInput: 0, cachedInput: 0, output: 0, thinking: 0, cacheStorageTokenHours: 0, unknown: true),
        );
        try {
            $value = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $unknown;
        }
        if (! is_array($value) || $httpStatus === 500) {
            return $unknown;
        }
        if (in_array($httpStatus, [400, 401, 403, 404, 422], true)) {
            if (array_key_exists('error', $value) && ! array_key_exists('usage', $value)) {
                return self::nonbillable($httpStatus);
            }

            return $unknown;
        }
        if ($httpStatus !== 200 || array_key_exists('error', $value)) {
            return $unknown;
        }
        $choices = $value['choices'] ?? null;
        if (! is_array($choices)) {
            return $unknown;
        }
        $model = self::provenance($value['model'] ?? null);
        $choice = $choices[0] ?? null;
        $rawFinish = is_array($choice) ? ($choice['finish_reason'] ?? null) : null;
        $finishReason = self::mapFinishReason($rawFinish);
        $rawUsage = $value['usage'] ?? null;
        if ($rawUsage === null) {
            return new ReceiptAssessment(
                outcome: ReceiptOutcome::Success,
                cost: null,
                policyEvidence: 'openai/1:generation_unknown_usage_conservative_bound',
                usage: $unknown->usage,
                resolvedModelVersion: $model,
                finishReason: $finishReason,
            );
        }
        $usage = self::generationUsage($rawUsage);

        return $usage === null ? $unknown : new ReceiptAssessment(
            outcome: ReceiptOutcome::Success,
            cost: null,
            policyEvidence: 'openai/1:generation_reported_usage',
            usage: $usage,
            resolvedModelVersion: $model,
            finishReason: $finishReason,
        );
    }

    private static function nonbillable(int $status): ReceiptAssessment
    {
        return new ReceiptAssessment(
            outcome: ReceiptOutcome::NonbillableRetry,
            cost: '0',
            policyEvidence: "openai/1:http_{$status}_known_nonbillable_retry",
            usage: new ProviderUsage(uncachedInput: 0, cachedInput: 0, output: 0, thinking: 0, cacheStorageTokenHours: 0, unknown: false),
        );
    }

    private static function generationUsage(mixed $value): ?ProviderUsage
    {
        if (! is_array($value)) {
            return null;
        }
        $prompt = $value['prompt_tokens'] ?? null;
        $promptDetails = $value['prompt_tokens_details'] ?? [];
        $cached = is_array($promptDetails) ? ($promptDetails['cached_tokens'] ?? 0) : null;
        $completion = $value['completion_tokens'] ?? null;
        $completionDetails = $value['completion_tokens_details'] ?? [];
        $reasoning = is_array($completionDetails) ? ($completionDetails['reasoning_tokens'] ?? 0) : null;
        if (! is_int($prompt) || ! is_int($cached) || ! is_int($completion) || ! is_int($reasoning)
            || min($prompt, $cached, $completion, $reasoning) < 0
            || max($prompt, $cached, $completion, $reasoning) > 1_000_000_000) {
            return null;
        }

        return new ProviderUsage(
            uncachedInput: max(0, $prompt - $cached),
            cachedInput: $cached,
            output: max(0, $completion - $reasoning),
            thinking: $reasoning,
            cacheStorageTokenHours: 0,
            unknown: false,
        );
    }

    private static function mapFinishReason(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        return match ($raw) {
            'stop' => 'STOP',
            'length' => 'MAX_TOKENS',
            'content_filter' => 'SAFETY',
            default => self::provenance($raw),
        };
    }

    private static function provenance(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._\/:+-]{0,127}$/D', $value) === 1 ? $value : null;
    }
}
