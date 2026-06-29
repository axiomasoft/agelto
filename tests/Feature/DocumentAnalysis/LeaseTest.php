<?php

declare(strict_types=1);

use App\Enums\AnalysisStatus;
use App\Models\DocumentAnalysis\DocumentAnalysis;
use App\Process\DocumentAnalysis\Jobs\PrimaryAnalysisJob;
use App\Process\DocumentAnalysis\PrimaryAnalysisProcessor;

test('acquireLease берёт свободную лизу и блокирует повторный захват, releaseLease освобождает', function () {
    $analysis = DocumentAnalysis::factory()->create();

    expect($analysis->acquireLease('worker-a'))->toBeTrue();

    // Пока лиза держится — другой воркер взять её не может.
    $other = DocumentAnalysis::query()->find($analysis->id);
    expect($other->acquireLease('worker-b'))->toBeFalse();

    $analysis->releaseLease();
    expect($other->acquireLease('worker-b'))->toBeTrue();
});

test('истёкшая лиза перезахватывается (stuck-recovery)', function () {
    $analysis = DocumentAnalysis::factory()->leaseExpired()->create();

    expect($analysis->acquireLease('worker-c'))->toBeTrue();
});

test('PrimaryAnalysisJob не обрабатывает запись под чужой активной лизой', function () {
    $analysis = DocumentAnalysis::factory()->prepared()->leasedBy('other')->create();

    // Лиза занята — handle вернётся до вызова процессора (Gemini не дёргается).
    (new PrimaryAnalysisJob($analysis->id))->handle(app(PrimaryAnalysisProcessor::class));

    $analysis->refresh();
    expect($analysis->status)->toBe(AnalysisStatus::Prepared)
        ->and($analysis->locked_by)->toBe('other');
});
