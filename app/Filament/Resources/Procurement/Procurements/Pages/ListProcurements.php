<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Procurements\Pages;

use App\Filament\Resources\Procurement\Procurements\ProcurementResource;
use Filament\Resources\Pages\ListRecords;

class ListProcurements extends ListRecords
{
    protected static string $resource = ProcurementResource::class;
}
