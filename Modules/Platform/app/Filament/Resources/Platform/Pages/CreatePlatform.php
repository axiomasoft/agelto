<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\Platform\Pages;

use Filament\Resources\Pages\CreateRecord;
use Platform\Filament\Resources\Platform\PlatformResource;

final class CreatePlatform extends CreateRecord
{
    protected static string $resource = PlatformResource::class;
}
