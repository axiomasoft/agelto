<?php

namespace App\Filament\Resources\Procurement\Crawler\Proxies\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ProxyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('service')
                    ->required(),
                TextInput::make('host')
                    ->required(),
                TextInput::make('port')
                    ->numeric(),
                TextInput::make('username'),
                // Креды прокси маскируются (admin-panel §2.1): скрытый ввод с
                // возможностью показать; пустое значение при правке не перетирает пароль.
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->dehydrated(fn (?string $state): bool => filled($state)),
                KeyValue::make('targets')
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->required(),
                TextInput::make('fails_count')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }
}
