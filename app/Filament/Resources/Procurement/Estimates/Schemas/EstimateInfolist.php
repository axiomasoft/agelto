<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Estimates\Schemas;

use App\Filament\Resources\Procurement\Ai\Analyses\AnalysisResource;
use App\Filament\Resources\Procurement\Procurements\ProcurementResource;
use App\Models\Procurement\Estimate\Estimate;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EstimateInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Смета и источник')
                    ->description('Смета связана с закупкой, документом и запуском анализа; экран доступен только для просмотра.')
                    ->schema([
                        TextEntry::make('title')
                            ->label('Смета')
                            ->columnSpanFull(),
                        TextEntry::make('procurement.title')
                            ->label('Закупка')
                            ->placeholder('-')
                            ->url(fn (Estimate $record): ?string => $record->procurement === null ? null : ProcurementResource::getUrl('view', ['record' => $record->procurement]))
                            ->columnSpanFull(),
                        TextEntry::make('documentVersion.document.title')
                            ->label('Документ')
                            ->placeholder('-')
                            ->columnSpanFull(),
                        TextEntry::make('analysis.id')
                            ->label('Анализ')
                            ->placeholder('-')
                            ->url(fn (Estimate $record): ?string => $record->analysis === null ? null : AnalysisResource::getUrl('view', ['record' => $record->analysis])),
                        TextEntry::make('validation_status')
                            ->label('Статус валидации')
                            ->badge(),
                        TextEntry::make('rows_extracted')
                            ->label('Строк извлечено')
                            ->placeholder('-'),
                        TextEntry::make('rows_expected')
                            ->label('Строк ожидалось')
                            ->placeholder('-'),
                    ])
                    ->columns(3),
                Section::make('Проверка результата')
                    ->description('Замечания относятся ко всей смете и не заменяют пометки отдельных позиций.')
                    ->schema([
                        // Пометка уровня сметы — сигнал
                        // валидатора, не адресующий конкретную строку (полнота, grand total).
                        TextEntry::make('validation_notes')
                            ->label('Замечания валидатора (уровень сметы)')
                            ->state(fn (Estimate $record): array => $record->validation_notes?->all() ?? [])
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('created_at')
                            ->label('Создана')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->label('Обновлена')
                            ->dateTime(),
                    ])
                    ->columns(2),
            ]);
    }
}
