<?php

declare(strict_types=1);

use App\Enums\AnalysisStatus;
use App\Models\DocumentAnalysis\DocumentAnalysis;

test('recordFailure инкрементит попытки, ставит backoff, а на исчерпании — dead-letter', function () {
    $analysis = DocumentAnalysis::factory()->primaryAnalyzing()->create();

    $analysis->recordFailure(AnalysisStatus::PrimaryAnalysisFailed, 'boom', maxAttempts: 2);
    $analysis->refresh();
    expect($analysis->status)->toBe(AnalysisStatus::PrimaryAnalysisFailed)
        ->and($analysis->attempts)->toBe(1)
        ->and($analysis->failed_permanently)->toBeFalse()
        ->and($analysis->next_retry_at)->not->toBeNull();

    $analysis->recordFailure(AnalysisStatus::PrimaryAnalysisFailed, 'boom2', maxAttempts: 2);
    $analysis->refresh();
    expect($analysis->attempts)->toBe(2)
        ->and($analysis->failed_permanently)->toBeTrue()
        ->and($analysis->next_retry_at)->toBeNull();
});
