<?php

namespace App\Filament\Resources\Procurement\Ai\Analyses\RelationManagers;

use App\Models\Procurement\Ai\Correction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Список коррекций документа (P9.2, Code Guidance — «не строить отдельный UI списка hints,
 * relation manager на ресурсе анализа») — read-only: коррекции создаются ТОЛЬКО через action
 * «Отклонить с коррекцией» (`ViewAnalysis`), которая уже привязывает `analysis_id`/`created_by`.
 */
class CorrectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'corrections';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('content')
            ->columns([
                TextColumn::make('reason')
                    ->badge(),
                TextColumn::make('field_path')
                    ->label('Поле')
                    ->placeholder('весь документ')
                    ->visibleFrom('md'),
                TextColumn::make('content')
                    ->label('Что не так')
                    ->wrap()
                    ->lineClamp(3)
                    ->tooltip(fn (?string $state): ?string => $state),
                TextColumn::make('hint')
                    ->label('Hint для промта')
                    ->placeholder('-')
                    ->wrap()
                    ->lineClamp(3)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->visibleFrom('md'),
                IconColumn::make('is_reusable')
                    ->label('Для всех документов типа')
                    ->boolean(),
                IconColumn::make('applied_at')
                    ->label('Применена')
                    ->state(fn (Correction $record): bool => $record->applied_at !== null)
                    ->boolean(),
                TextColumn::make('createdBy.name')
                    ->label('Автор')
                    ->placeholder('-')
                    ->visibleFrom('md'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                //
            ])
            ->recordActions([
                //
            ])
            ->toolbarActions([
                //
            ]);
    }
}
