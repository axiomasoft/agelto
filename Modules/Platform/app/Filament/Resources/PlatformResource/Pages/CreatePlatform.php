<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\PlatformResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Platform\Filament\Resources\PlatformResource;

final class CreatePlatform extends CreateRecord
{
    protected static string $resource = PlatformResource::class;
}
