<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Estimates\Tables;

use App\Filament\Resources\Procurement\Ai\Analyses\AnalysisResource;
use App\Filament\Resources\Procurement\Procurements\ProcurementResource;
use App\Models\Procurement\Estimate\Estimate;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EstimatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Смета')
                    ->wrapHeader()
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (?string $state): ?string => $state),
                TextColumn::make('procurement.title')
                    ->label('Закупка')
                    ->placeholder('-')
                    ->url(fn (Estimate $record): ?string => $record->procurement === null ? null : ProcurementResource::getUrl('view', ['record' => $record->procurement]))
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (?string $state): ?string => $state),
                TextColumn::make('documentVersion.document.title')
                    ->label('Документ')
                    ->placeholder('-')
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->visibleFrom('md'),
                TextColumn::make('analysis.id')
                    ->label('Анализ')
                    ->placeholder('-')
                    ->formatStateUsing(static fn (int $state): string => "Анализ #{$state}")
                    ->url(fn (Estimate $record): ?string => $record->analysis === null ? null : AnalysisResource::getUrl('view', ['record' => $record->analysis]))
                    ->visibleFrom('md'),
                TextColumn::make('validation_status')
                    ->label('Статус валидации')
                    ->badge()
                    ->sortable(),
                // Агрегат withCount из getEloquentQuery() сохраняет постоянное число запросов списка.
                TextColumn::make('marked_items_count')
                    ->label('Помечено')
                    ->state(fn (Estimate $record): string => "{$record->marked_items_count} из {$record->items_count}")
                    ->visibleFrom('md'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            // Read-only: только просмотр.
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
