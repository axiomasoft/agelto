<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\Analyses\Pages;

use App\Filament\Resources\Procurement\Ai\Analyses\AnalysisResource;
use Filament\Resources\Pages\ListRecords;

class ListAnalyses extends ListRecords
{
    protected static string $resource = AnalysisResource::class;
}
