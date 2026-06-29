<?php

declare(strict_types=1);

use App\Enums\AnalysisStatus;
use App\Enums\Crawling\CrawlingStatus;
use App\Models\Crawler\Link;
use App\Models\Crawler\LinkAttachment;
use App\Models\DocumentAnalysis\DocumentAnalysis;

test('агрегат-статус ссылки пересчитывается из анализов: терминальный успех → COMPLETED', function () {
    $link = Link::factory()->processing()->create();
    $attachment = LinkAttachment::factory()->for($link)->create();
    $analysis = DocumentAnalysis::factory()
        ->forAttachment($attachment)
        ->estimateAnalyzing()
        ->create();

    // Переход в терминальный статус пересчитывает агрегат ссылки.
    $analysis->transitionStatus(AnalysisStatus::Completed);

    expect($link->refresh()->status)->toBe(CrawlingStatus::COMPLETED);
});
