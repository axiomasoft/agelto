<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Estimates\Pages;

use App\Filament\Resources\Procurement\Estimates\EstimateResource;
use Filament\Resources\Pages\ViewRecord;

class ViewEstimate extends ViewRecord
{
    protected static string $resource = EstimateResource::class;
}
