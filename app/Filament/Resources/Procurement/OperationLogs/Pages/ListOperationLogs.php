<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\OperationLogs\Pages;

use App\Filament\Resources\Procurement\OperationLogs\OperationLogResource;
use Filament\Resources\Pages\ListRecords;

final class ListOperationLogs extends ListRecords
{
    protected static string $resource = OperationLogResource::class;
}
