<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Process\Incidents\Pages;

use App\Filament\Resources\Procurement\Process\Incidents\IncidentResource;
use Filament\Resources\Pages\ListRecords;

final class ListIncidents extends ListRecords
{
    protected static string $resource = IncidentResource::class;
}
