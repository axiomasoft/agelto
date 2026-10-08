<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Crawler\SourceCircuits\Pages;

use App\Filament\Resources\Procurement\Crawler\SourceCircuits\SourceCircuitResource;
use Filament\Resources\Pages\ListRecords;

final class ListSourceCircuits extends ListRecords
{
    protected static string $resource = SourceCircuitResource::class;
}
