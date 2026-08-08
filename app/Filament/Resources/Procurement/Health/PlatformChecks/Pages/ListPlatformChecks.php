<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Health\PlatformChecks\Pages;

use App\Filament\Resources\Procurement\Health\PlatformChecks\PlatformCheckResource;
use Filament\Resources\Pages\ListRecords;

class ListPlatformChecks extends ListRecords
{
    protected static string $resource = PlatformCheckResource::class;
}
