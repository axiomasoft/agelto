<?php

declare(strict_types=1);

use App\Enums\AnalysisStatus;
use App\Models\DocumentAnalysis\DocumentAnalysis;
use App\Process\DocumentAnalysis\PrimaryAnalysisProcessor;

beforeEach(function () {
    $this->processor = app(PrimaryAnalysisProcessor::class);
});

test('fromUrl создаёт запись со статусом Preparing и идемпотентен', function () {
    $url = 'https://example.com/same-document.pdf';

    $first = $this->processor->fromUrl($url);
    $second = $this->processor->fromUrl($url);

    expect($first->id)->toBe($second->id)
        ->and($first->status)->toBe(AnalysisStatus::Preparing)
        ->and(DocumentAnalysis::query()->count())->toBe(1);
});
