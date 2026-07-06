<?php

namespace App\Filament\Resources\Procurement\Crawler\IgnoreRules\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class IgnoreRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('registry_id')
                    ->relationship('registry', 'name'),
                Textarea::make('field')
                    ->required()
                    ->columnSpanFull(),
                Textarea::make('operator')
                    ->required()
                    ->columnSpanFull(),
                Textarea::make('value')
                    ->required()
                    ->columnSpanFull(),
                Textarea::make('reason_category')
                    ->required()
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->required(),
            ]);
    }
}
