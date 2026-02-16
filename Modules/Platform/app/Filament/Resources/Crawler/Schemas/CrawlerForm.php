<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\Crawler\Schemas;

use Eis\Filament\Components\EisCrawlerForm;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Platform\Models\Platform;

final class CrawlerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->schema([
                Section::make('Основная информация')
                    ->schema([
                        Select::make('platform_id')
                            ->label('Платформа')
                            ->relationship('platform', 'name')
                            ->live()
                            ->required(),

                        TextInput::make('url')
                            ->label('Базовая ссылка')
                            ->url()
                            ->required()
                            ->columnSpanFull(),
                    ])->columns(2),

                Section::make('Параметры фильтрации')
                    ->schema(function (Get $get): array {
                        $platformId = $get('platform_id');
                        if (! $platformId) {
                            return [];
                        }

                        $platform = Platform::find($platformId);

                        // Проверка по слагу
                        return match ($platform?->code) {
                            'eis' => EisCrawlerForm::schema(),
                            default => [],
                        };
                    })
                    ->visible(fn (Get $get) => filled($get('platform_id'))),
            ]);
    }
}
