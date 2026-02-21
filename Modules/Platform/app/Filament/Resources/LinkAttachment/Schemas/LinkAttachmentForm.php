<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\LinkAttachment\Schemas;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Platform\Enums\Crawling\CrawlingStatus;
use Platform\Filament\Resources\Link\LinkResource;

final class LinkAttachmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->schema([
                Section::make('Основное')
                    ->schema([
                        TextInput::make('link_reg_number')
                            ->label('Ссылка для запись обработки (номер закупки)')
                            ->dehydrated(false)
                            ->readOnly()
                            ->visible(fn(?Model $record): bool => (bool) $record?->link_id)
                            ->suffixAction(
                                Action::make('openLink')
                                    ->label('Перейти к ссылке')
                                    ->icon(Heroicon::ArrowTopRightOnSquare)
                                    ->url(fn(?Model $record): string => $record ? LinkResource::getUrl('edit', ['record' => $record->link_id]) : '#')
                                    ->openUrlInNewTab(),
                            ),
                        TextInput::make('external_id')
                            ->label('Внешний ID вложения')
                            ->readOnly(),
                        TextInput::make('name')
                            ->label('Название файла')
                            ->readOnly(),
                        TextInput::make('extension')
                            ->label('Расширение')
                            ->readOnly(),
                        TextInput::make('url')
                            ->label('Ссылка на файл вложения')
                            ->url()
                            ->columnSpanFull()
                            ->readOnly()
                            ->suffixAction(
                                Action::make('openUrl')
                                    ->label('Открыть в новом окне')
                                    ->icon(Heroicon::ArrowTopRightOnSquare)
                                    ->url(fn(Get $get): ?string => $get('url') ?: null)
                                    ->openUrlInNewTab()
                                    ->visible(fn(Get $get): bool => filled($get('url'))),
                            ),

                        Select::make('status')
                            ->label('Статус')
                            ->options(CrawlingStatus::class)
                            ->default(CrawlingStatus::PENDING)
                            ->disabled(),
                    ])
                    ->columns(2),
            ]);
    }
}
