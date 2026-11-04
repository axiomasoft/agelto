<?php

namespace App\Filament\Resources\Procurement\Platform\StatusMappings\Pages;

use App\Enums\Procurement\StatusMappingApplicability;
use App\Filament\Resources\Procurement\Platform\StatusMappings\StatusMappingResource;
use App\Models\Procurement\Platform\StatusMapping;
use Filament\Resources\Pages\EditRecord;

final class EditStatusMapping extends EditRecord
{
    protected static string $resource = StatusMappingResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();
        if ($record instanceof StatusMapping) {
            $data['applicability'] = $record->registry_id !== null
                ? StatusMappingApplicability::Registry->value
                : StatusMappingApplicability::Platform->value;
            $data['selected_platform_id'] = $record->registry?->platform_id;
        }

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['platform_id'], $data['registry_id'], $data['source_stage']);

        return $data;
    }
}
