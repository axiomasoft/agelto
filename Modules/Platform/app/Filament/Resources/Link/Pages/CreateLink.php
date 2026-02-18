<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\Link\Pages;

use Filament\Resources\Pages\CreateRecord;
use Platform\Filament\Resources\Link\LinkResource;

final class CreateLink extends CreateRecord
{
    protected static string $resource = LinkResource::class;
}
