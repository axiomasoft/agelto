<?php

namespace App\Filament\Resources\Procurement\Ai\AiRoutes\Schemas;

use App\Enums\Procurement\LlmPurpose;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class AiRouteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('document_type')
                    ->required()
                    ->columnSpanFull(),
                Select::make('purpose')
                    ->options(LlmPurpose::class)
                    ->required(),
                Select::make('provider_id')
                    ->relationship('provider', 'id')
                    ->required(),
                Textarea::make('model')
                    ->required()
                    ->columnSpanFull(),
                KeyValue::make('options')
                    ->columnSpanFull(),
                TextInput::make('priority')
                    ->required()
                    ->numeric()
                    ->default(0),
                Toggle::make('is_active')
                    ->required(),
                Toggle::make('shadow')
                    ->required(),
            ]);
    }
}
