<?php

namespace App\Filament\Resources\Procurement\Ai\Providers\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProviderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Подключение к AI-провайдеру')
                    ->description('Код и драйвер определяют интеграцию; адрес указывает endpoint провайдера.')
                    ->schema([
                        Textarea::make('code')
                            ->label('Код')
                            ->required()
                            ->columnSpanFull(),
                        Textarea::make('driver')
                            ->label('Драйвер')
                            ->required()
                            ->columnSpanFull(),
                        Textarea::make('base_url')
                            ->label('Базовый адрес')
                            ->helperText('Оставьте пустым, чтобы драйвер использовал свой стандартный endpoint.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Параметры и доступность')
                    ->description('Дополнительные параметры действуют только для этого провайдера.')
                    ->schema([
                        KeyValue::make('options')
                            ->label('Параметры')
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label('Активен')
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }
}
