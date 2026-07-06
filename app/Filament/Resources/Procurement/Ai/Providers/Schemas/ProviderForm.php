<?php

namespace App\Filament\Resources\Procurement\Ai\Providers\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ProviderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('code')
                    ->required()
                    ->columnSpanFull(),
                Textarea::make('driver')
                    ->required()
                    ->columnSpanFull(),
                Textarea::make('base_url')
                    ->columnSpanFull(),
                KeyValue::make('options')
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->required(),
            ]);
    }
}
