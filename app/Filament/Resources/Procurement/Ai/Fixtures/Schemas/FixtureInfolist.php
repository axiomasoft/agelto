<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\Fixtures\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class FixtureInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('code')
                    ->label('Код'),
                TextEntry::make('title')
                    ->label('Название'),
                TextEntry::make('document_type')
                    ->label('Тип документа (вердикт)')
                    ->badge(),
                TextEntry::make('owner_document_type')
                    ->label('Владелец профиля')
                    ->badge(),
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
            ]);
    }
}
