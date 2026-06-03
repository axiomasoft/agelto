<?php

declare(strict_types=1);

namespace App\Filament\Resources\App\Pages;

use Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\App\PlatformResource;

final class CreatePlatform extends CreateRecord
{
    protected static string $resource = PlatformResource::class;
}
