<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\Platform\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;

final class PlatformForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Название')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('code')
                            ->label('Код')
                            ->required()
                            ->disabledOn('edit')
                            ->maxLength(32),
                        TextInput::make('url')
                            ->label('Базовая ссылка')
                            ->url()
                            ->required()
                            ->maxLength(255),
                    ]),
            ]);
    }
}
