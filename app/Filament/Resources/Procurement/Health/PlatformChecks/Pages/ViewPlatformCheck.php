<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Health\PlatformChecks\Pages;

use App\Filament\Resources\Procurement\Health\PlatformChecks\PlatformCheckResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPlatformCheck extends ViewRecord
{
    protected static string $resource = PlatformCheckResource::class;
}
