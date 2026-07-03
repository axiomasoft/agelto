<?php

declare(strict_types=1);

use App\Dto\Prompt\Step;
use App\Models\DocumentAnalysis\DocumentAnalysis;

test('DocumentAnalysisResponse сохраняет расход токенов Gemini', function () {
    $analysis = DocumentAnalysis::factory()->completed()->create();

    $response = $analysis->responses()->create([
        'step' => Step::PrimaryAnalysis,
        'prompt' => 'анализ',
        'success' => true,
        'prompt_tokens' => 100,
        'completion_tokens' => 40,
        'total_tokens' => 140,
    ]);

    $fresh = $response->fresh();

    expect($fresh->prompt_tokens)->toBe(100)
        ->and($fresh->completion_tokens)->toBe(40)
        ->and($fresh->total_tokens)->toBe(140);
});
