<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Estimates\Pages;

use App\Filament\Resources\Procurement\Estimates\EstimateResource;
use Filament\Resources\Pages\ListRecords;

class ListEstimates extends ListRecords
{
    protected static string $resource = EstimateResource::class;
}
