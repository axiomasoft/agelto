<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\Link\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Platform\Enums\Crawling\CrawlingStatus;

final class LinkForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->schema([
                Section::make('Основное')
                    ->schema([
                        Select::make('platform_id')
                            ->label('Платформа')
                            ->relationship(name: 'platform', titleAttribute: 'name')
                            ->required()
                            ->searchable(),
                        TextInput::make('reg_number')
                            ->label('Рег. номер')
                            ->maxLength(255),
                        TextInput::make('detail_url')
                            ->label('URL страницы')
                            ->url()
                            ->maxLength(2048)
                            ->columnSpanFull(),
                        Select::make('status')
                            ->label('Статус')
                            ->options(CrawlingStatus::class)
                            ->required(),
                    ])
                    ->columns(2),
                Section::make('Даты')
                    ->schema([
                        DatePicker::make('published_at')
                            ->label('Опубликовано')
                            ->native(false)
                            ->readOnly(),
                        DatePicker::make('platform_updated_at')
                            ->label('Обновлено на площадке')
                            ->native(false)
                            ->readOnly(),
                        DatePicker::make('submission_end_at')
                            ->label('Окончание приёма')
                            ->native(false)
                            ->readOnly(),
                        DatePicker::make('completed_at')
                            ->label('Завершено')
                            ->native(false)
                            ->readOnly(),
                        DatePicker::make('next_retry_at')
                            ->label('След. попытка')
                            ->native(false)
                            ->readOnly(),
                    ])
                    ->columns(2)
                    ->collapsible(),
            ]);
    }
}
