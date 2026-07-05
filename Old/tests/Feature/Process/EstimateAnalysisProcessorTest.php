<?php

declare(strict_types=1);

use App\Enums\AnalysisStatus;
use App\Models\DocumentAnalysis\DocumentAnalysis;
use App\Process\EstimateAnalysis\Jobs\EstimateAnalysisJob;

test('новая DocumentAnalysis получает статус Preparing по умолчанию', function () {
    $analysis = DocumentAnalysis::query()->create([
        'source' => 'https://example.com/doc.pdf',
    ]);

    expect($analysis->refresh()->status)->toBe(AnalysisStatus::Preparing);
});

test('job не трогает документ в финальном статусе (guard идемпотентности)', function () {
    foreach ([AnalysisStatus::EstimateAnalyzed, AnalysisStatus::Completed] as $finalStatus) {
        $analysis = DocumentAnalysis::query()->create([
            'source' => "https://example.com/{$finalStatus->value}.xlsx",
            'status' => $finalStatus->value,
        ]);

        // Реальный процессор из контейнера: guard в job завершает обработку
        // раньше, поэтому Gemini/сеть не вызываются, статус и responses не меняются.
        (new EstimateAnalysisJob(documentAnalysisId: $analysis->id))
            ->handle(app(App\Process\EstimateAnalysis\EstimateAnalysisProcessor::class));

        expect($analysis->refresh()->status)->toBe($finalStatus)
            ->and($analysis->responses()->count())->toBe(0);
    }
});
