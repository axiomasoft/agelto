<?php

declare(strict_types=1);

namespace App\Filament\Resources\Link\Pages;

use Filament\Resources\Pages\CreateRecord;
use App\Filament\Resources\Link\LinkResource;

final class CreateLink extends CreateRecord
{
    protected static string $resource = LinkResource::class;
}
