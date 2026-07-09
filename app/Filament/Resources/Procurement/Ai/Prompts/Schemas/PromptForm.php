<?php

namespace App\Filament\Resources\Procurement\Ai\Prompts\Schemas;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PromptForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Placeholder::make('is_protected_hint')
                    ->label('')
                    ->content('Канонические промты (is_protected) правятся только через git + db:seed. Здесь создаются только операторские дополняющие записи — они не защищены.')
                    ->columnSpanFull(),
                Textarea::make('key')
                    ->required()
                    ->columnSpanFull(),
                Textarea::make('step')
                    ->required()
                    ->columnSpanFull(),
                Textarea::make('content')
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('version')
                    ->required()
                    ->numeric(),
                TextInput::make('priority')
                    ->required()
                    ->numeric()
                    ->default(0),
                Toggle::make('is_active')
                    ->required(),
            ]);
    }
}
