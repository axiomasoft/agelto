<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Crawler\SourceIncidents\Pages;

use App\Filament\Resources\Procurement\Crawler\SourceIncidents\SourceIncidentResource;
use Filament\Resources\Pages\ListRecords;

final class ListSourceIncidents extends ListRecords
{
    protected static string $resource = SourceIncidentResource::class;
}
