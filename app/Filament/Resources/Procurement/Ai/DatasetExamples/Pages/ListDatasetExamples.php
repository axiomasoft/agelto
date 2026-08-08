<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\DatasetExamples\Pages;

use App\Filament\Resources\Procurement\Ai\DatasetExamples\DatasetExampleResource;
use Filament\Resources\Pages\ListRecords;

class ListDatasetExamples extends ListRecords
{
    protected static string $resource = DatasetExampleResource::class;
}
