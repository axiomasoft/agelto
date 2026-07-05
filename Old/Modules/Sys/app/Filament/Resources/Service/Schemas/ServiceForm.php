<?php

declare(strict_types=1);

namespace Sys\Filament\Resources\Service\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ServiceForm
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
                            ->maxLength(50),
                        TextInput::make('code')
                            ->label('Код')
                            ->required()
                            ->disabledOn('edit')
                            ->maxLength(16),
                    ]),
            ]);
    }
}
