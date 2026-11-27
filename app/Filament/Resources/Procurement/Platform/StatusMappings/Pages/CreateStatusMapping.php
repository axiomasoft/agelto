<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Platform\StatusMappings\Pages;

use App\Filament\Resources\Procurement\Platform\StatusMappings\StatusMappingResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateStatusMapping extends CreateRecord
{
    protected static string $resource = StatusMappingResource::class;
}
