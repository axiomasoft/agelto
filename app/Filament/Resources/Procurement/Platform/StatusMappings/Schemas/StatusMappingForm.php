<?php

namespace App\Filament\Resources\Procurement\Platform\StatusMappings\Schemas;

use App\Enums\Procurement\ProcurementStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

final class StatusMappingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('registry_id')
                    ->label('ID реестра')
                    ->disabled()
                    ->dehydrated(false),
                TextInput::make('source_stage')
                    ->label('Этап площадки')
                    ->disabled()
                    ->dehydrated(false),
                Select::make('canonical_status')
                    ->label('Канонический статус')
                    ->options(ProcurementStatus::class)
                    ->required(),
            ]);
    }
}
