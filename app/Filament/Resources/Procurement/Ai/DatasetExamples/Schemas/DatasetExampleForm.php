<?php

namespace App\Filament\Resources\Procurement\Ai\DatasetExamples\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class DatasetExampleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('document_version_id')
                    ->relationship('documentVersion', 'id')
                    ->required(),
                Textarea::make('task')
                    ->required()
                    ->columnSpanFull(),
                KeyValue::make('gold')
                    ->columnSpanFull(),
                Textarea::make('gold_ref')
                    ->columnSpanFull(),
                Textarea::make('source')
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('verified_by')
                    ->numeric(),
                DateTimePicker::make('verified_at'),
                TextInput::make('version')
                    ->required()
                    ->numeric()
                    ->default(1),
            ]);
    }
}
