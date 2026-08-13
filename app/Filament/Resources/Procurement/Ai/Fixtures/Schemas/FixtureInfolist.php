<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\Fixtures\Schemas;

use App\Filament\Resources\Procurement\Ai\DatasetExamples\DatasetExampleResource;
use App\Filament\Resources\Procurement\Ai\Fixtures\FixtureResource;
use App\Models\Procurement\Ai\Fixture;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FixtureInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Эталон и проверенные ответы')
                    ->description('Эталонный документ используется для воспроизводимой проверки качества анализа.')
                    ->schema([
                        TextEntry::make('code')
                            ->label('Код'),
                        TextEntry::make('gold_navigation')
                            ->label('Золото')
                            ->state('Открыть gold-смету')
                            ->url(fn (Fixture $record): string => FixtureResource::getUrl('gold', ['record' => $record])),
                        RepeatableEntry::make('datasetExamples')
                            ->label('Эталонные ответы')
                            ->schema([
                                TextEntry::make('id')
                                    ->label('Ответ')
                                    ->url(fn ($record): string => DatasetExampleResource::getUrl('view', ['record' => $record])),
                                TextEntry::make('task')->label('Задача'),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),
                        TextEntry::make('title')
                            ->label('Название'),
                        TextEntry::make('document_type')
                            ->label('Тип документа (вердикт)')
                            ->badge(),
                        TextEntry::make('owner_document_type')
                            ->label('Владелец профиля')
                            ->badge(),
                    ])
                    ->columns(3),
                Section::make('Профиль и источник')
                    ->description('Технические сведения источника доступны для проверки происхождения, но не изменяют эталон.')
                    ->schema([
                        KeyValueEntry::make('type_profile')
                            ->label('Профиль типа')
                            ->columnSpanFull(),
                        TextEntry::make('source_format')
                            ->label('Формат исходника'),
                        TextEntry::make('size_bytes')
                            ->label('Размер, байт')
                            ->numeric(),
                        TextEntry::make('mime')
                            ->label('MIME'),
                        TextEntry::make('original_filename')
                            ->label('Исходное имя файла'),
                        TextEntry::make('sha256')
                            ->label('sha256'),
                        TextEntry::make('storage_path')
                            ->label('Путь на диске'),
                    ])
                    ->columns(3),
                Section::make('Рабочие пометки')
                    ->description('Пометки и признак проверки помогают воспроизводить работу с эталоном.')
                    ->schema([
                        TextEntry::make('generator')
                            ->placeholder('-'),
                        TextEntry::make('cluster_id')
                            ->placeholder('-'),
                        TextEntry::make('quirks')
                            ->badge()
                            ->placeholder('-'),
                        IconEntry::make('is_verified')
                            ->label('Проверен')
                            ->boolean(),
                        TextEntry::make('notes')
                            ->label('Комментарий')
                            ->placeholder('-')
                            ->columnSpanFull(),
                        TextEntry::make('created_at')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->dateTime(),
                    ])
                    ->columns(3),
            ]);
    }
}
