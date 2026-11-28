<?php

declare(strict_types=1);

use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\ReceiptOutcome;
use App\Services\Llm\Providers\OpenAiReceiptPolicy;

test('политика квитанций учитывает кэшированные и reasoning-токены и версию модели при HTTP 200', function (): void {
    $policy = new OpenAiReceiptPolicy;
    $body = json_encode([
        'model' => 'o4-mini-2025-04-16',
        'choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{}']]],
        'usage' => [
            'prompt_tokens' => 1000,
            'prompt_tokens_details' => ['cached_tokens' => 250],
            'completion_tokens' => 400,
            'completion_tokens_details' => ['reasoning_tokens' => 120],
        ],
    ], JSON_THROW_ON_ERROR);

    $assessment = $policy->assess(OperationPurpose::Generate, 200, $body);

    expect($assessment->outcome)->toBe(ReceiptOutcome::Success)
        ->and($assessment->resolvedModelVersion)->toBe('o4-mini-2025-04-16')
        ->and($assessment->finishReason)->toBe('STOP')
        ->and($assessment->usage?->uncachedInput)->toBe(750)
        ->and($assessment->usage?->cachedInput)->toBe(250)
        ->and($assessment->usage?->output)->toBe(280)
        ->and($assessment->usage?->thinking)->toBe(120)
        ->and($assessment->usage?->unknown)->toBeFalse();
});

test('политика квитанций классифицирует 429, 503, 400 и 401 как нетарифицируемый повтор', function (int $status, string $body): void {
    $policy = new OpenAiReceiptPolicy;
    $assessment = $policy->assess(OperationPurpose::Generate, $status, $body);

    expect($assessment->outcome)->toBe(ReceiptOutcome::NonbillableRetry)
        ->and($assessment->cost)->toBe('0.00000000')
        ->and($assessment->usage?->unknown)->toBeFalse();
})->with([
    '429 rate limit' => [429, '{"error":{"message":"Rate limit"}}'],
    '503 service unavailable' => [503, 'Service Unavailable'],
    '400 bad request' => [400, '{"error":{"message":"Invalid schema"}}'],
    '401 unauthorized' => [401, '{"error":{"message":"Invalid API key"}}'],
]);

test('политика квитанций классифицирует 500 и битый JSON как unknown', function (int $status, string $body): void {
    $policy = new OpenAiReceiptPolicy;
    $assessment = $policy->assess(OperationPurpose::Generate, $status, $body);

    expect($assessment->outcome)->toBe(ReceiptOutcome::Unknown)
        ->and($assessment->cost)->toBeNull()
        ->and($assessment->usage?->unknown)->toBeTrue();
})->with([
    '500 internal error' => [500, '{"error":{"message":"Internal error"}}'],
    '200 malformed json' => [200, '{"choices":['],
]);
