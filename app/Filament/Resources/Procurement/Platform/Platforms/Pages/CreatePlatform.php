<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Platform\Platforms\Pages;

use App\Filament\Resources\Procurement\Platform\Platforms\PlatformResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePlatform extends CreateRecord
{
    protected static string $resource = PlatformResource::class;
}
