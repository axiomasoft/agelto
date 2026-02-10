<?php

declare(strict_types=1);

namespace Sys\Filament\Resources\Proxy\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ProxyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('service_id')
                            ->label('Сервис')
                            ->relationship(name: 'service', titleAttribute: 'name')
                            ->required()
                            ->searchable()
                            ->preload(),
                        TextInput::make('username')
                            ->label('Логин')
                            ->required()
                            ->maxLength(50),
                        TextInput::make('password')
                            ->label('Пароль')
                            ->required()
                            ->maxLength(50),
                        TextInput::make('ip_address')
                            ->label('IP-адрес')
                            ->required()
                            ->maxLength(32),
                        TextInput::make('port')
                            ->label('Порт')
                            ->required()
                            ->maxLength(6),
                        Toggle::make('is_active')
                            ->label('Активен')
                            ->default(true),
                    ]),
            ]);
    }
}
