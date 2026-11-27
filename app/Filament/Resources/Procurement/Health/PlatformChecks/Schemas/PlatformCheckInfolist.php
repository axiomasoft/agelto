<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Health\PlatformChecks\Schemas;

use App\Enums\Procurement\Health\PlatformCheckVerdict;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PlatformCheckInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Проверка')->schema([
                TextEntry::make('platform.name')->label('Площадка'),
                TextEntry::make('check_kind')->label('Вид')->badge(),
                TextEntry::make('verdict')->label('Вердикт')->badge()
                    ->color(static fn (PlatformCheckVerdict $state): string => $state->color()),
                TextEntry::make('sample_size')->label('Размер выборки')->numeric(),
                TextEntry::make('requests_used')->label('Запросов использовано')->numeric(),
                TextEntry::make('window_from')->label('Окно с')->dateTime()->placeholder('—'),
                TextEntry::make('window_to')->label('Окно по')->dateTime()->placeholder('—'),
                TextEntry::make('checked_at')->label('Проверено')->dateTime(),
            ])->columns(3),
            Section::make('Измеренные значения')->schema([
                KeyValueEntry::make('measured')->label('Показатели')->columnSpanFull(),
            ]),
            Section::make('Контекст')->schema([
                TextEntry::make('message')->label('Сообщение')->columnSpanFull(),
                TextEntry::make('baseline_sha256')->label('SHA-256 эталона')->placeholder('—')->columnSpanFull(),
            ]),
        ]);
    }
}
