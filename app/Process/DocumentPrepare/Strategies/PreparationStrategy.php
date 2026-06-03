<?php

declare(strict_types=1);

namespace App\Process\DocumentPrepare\Strategies;

use App\Models\DocumentAnalysis\DocumentAnalysis;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

interface PreparationStrategy
{
    public function canProcess(Media $media): bool;

    public function process(DocumentAnalysis $analysis, Media $media): void;
}
