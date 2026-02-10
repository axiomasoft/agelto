<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\Crawler\Schemas;

use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class CrawlerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('')
                    ->columnSpanFull()
                    ->schema([
                        Select::make('platform_id')
                            ->label('Площадка')
                            ->relationship(name: 'platform', titleAttribute: 'name')
                            ->required()
                            ->searchable()
                            ->preload(),
                        TextInput::make('url')
                            ->label('Ссылка для сбора данных')
                            ->url()
                            ->nullable(),
                        KeyValue::make('filter')
                            ->label('Фильтр (ключ — значение)')
                            ->nullable(),
                    ]),
            ]);
    }
}
