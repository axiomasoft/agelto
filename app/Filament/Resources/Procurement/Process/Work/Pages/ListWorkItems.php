<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Process\Work\Pages;

use App\Filament\Resources\Procurement\Process\Work\WorkItemResource;
use Filament\Resources\Pages\ListRecords;

final class ListWorkItems extends ListRecords
{
    protected static string $resource = WorkItemResource::class;
}
