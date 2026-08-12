<?php

namespace App\Filament\Resources\Procurement\Platform\StatusMappings\Pages;

use App\Filament\Resources\Procurement\Platform\StatusMappings\StatusMappingResource;
use Filament\Resources\Pages\EditRecord;

final class EditStatusMapping extends EditRecord
{
    protected static string $resource = StatusMappingResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['platform_id'], $data['registry_id'], $data['source_stage']);

        return $data;
    }
}
