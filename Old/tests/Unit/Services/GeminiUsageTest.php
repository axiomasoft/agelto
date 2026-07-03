<?php

declare(strict_types=1);

use App\Services\GeminiService;

test('извлекает токены из usageMetadata ответа Gemini', function () {
    $response = (object) [
        'usageMetadata' => (object) [
            'promptTokenCount' => 100,
            'candidatesTokenCount' => 40,
            'totalTokenCount' => 140,
        ],
    ];

    expect(GeminiService::usageFromResponse($response))->toBe([
        'prompt_tokens' => 100,
        'completion_tokens' => 40,
        'total_tokens' => 140,
    ]);
});

test('возвращает null, если usageMetadata отсутствует', function () {
    expect(GeminiService::usageFromResponse((object) []))->toBeNull();
});
