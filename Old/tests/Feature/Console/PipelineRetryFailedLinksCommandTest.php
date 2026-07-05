<?php

declare(strict_types=1);

use App\Enums\AnalysisStatus;
use App\Models\Crawler\Link;
use App\Models\Crawler\LinkAttachment;
use App\Models\DocumentAnalysis\DocumentAnalysis;
use App\Process\DocumentAnalysis\Jobs\PrimaryAnalysisJob;
use App\Process\DocumentPrepare\Jobs\PrepareDocumentJob;
use App\Process\EstimateAnalysis\Jobs\EstimateAnalysisJob;
use Illuminate\Support\Facades\Queue;

test('перезапускает провал первичного анализа только с этой стадии', function () {
    Queue::fake();

    $attachment = LinkAttachment::factory()->for(Link::factory()->processing())->create();
    $analysis = DocumentAnalysis::factory()
        ->forAttachment($attachment)
        ->primaryAnalysisFailed()
        ->create();

    $this->artisan('app:pipeline:retry-failed-links')->assertSuccessful();

    // Claim перевёл анализ в Prepared; диспатчен только PrimaryAnalysisJob (без повторной подготовки).
    expect($analysis->refresh()->status)->toBe(AnalysisStatus::Prepared);
    Queue::assertPushed(PrimaryAnalysisJob::class);
    Queue::assertNotPushed(PrepareDocumentJob::class);
});

test('перезапускает провал анализа сметы без повторного скрининга', function () {
    Queue::fake();

    $attachment = LinkAttachment::factory()->for(Link::factory()->processing())->create();
    $analysis = DocumentAnalysis::factory()
        ->forAttachment($attachment)
        ->estimateAnalysisFailed()
        ->create();

    $this->artisan('app:pipeline:retry-failed-links')->assertSuccessful();

    // Хвост — только EstimateAnalysisJob; повторного скрининга/подготовки нет.
    expect($analysis->refresh()->status)->toBe(AnalysisStatus::PrimaryAnalyzed);
    Queue::assertPushed(EstimateAnalysisJob::class);
    Queue::assertNotPushed(PrimaryAnalysisJob::class);
    Queue::assertNotPushed(PrepareDocumentJob::class);
});

test('не трогает dead-letter (failed_permanently)', function () {
    Queue::fake();

    $analysis = DocumentAnalysis::factory()
        ->estimateAnalysisFailed()
        ->deadLetter()
        ->create();

    $this->artisan('app:pipeline:retry-failed-links')->assertSuccessful();

    expect($analysis->refresh()->status)->toBe(AnalysisStatus::EstimateAnalysisFailed);
    Queue::assertNothingPushed();
});
