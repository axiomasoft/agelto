<?php

namespace App\Filament\Resources\Procurement\Platform\Registries\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RegistryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Источник реестра')
                    ->description('Реестр принадлежит площадке и задаёт источник, из которого создаются задания сбора.')
                    ->schema([
                        TextInput::make('uuid')
                            ->label('UUID')
                            ->disabled()
                            ->dehydrated(false),
                        Select::make('platform_id')
                            ->relationship('platform', 'name')
                            ->required(),
                        TextInput::make('code')
                            ->required(),
                        TextInput::make('name')
                            ->required(),
                    ])
                    ->columns(2),
                Section::make('Параметры реестра')
                    ->description('Дополнительные параметры применяются к этому реестру и не меняют настройки площадки.')
                    ->schema([
                        KeyValue::make('settings')
                            ->helperText('Указывайте только параметры, которые поддерживает адаптер выбранной площадки.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
