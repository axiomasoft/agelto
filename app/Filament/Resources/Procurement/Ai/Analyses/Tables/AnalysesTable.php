<?php

namespace App\Filament\Resources\Procurement\Ai\Analyses\Tables;

use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\ReviewStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AnalysesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('documentVersion.document.title')
                    ->label('Документ')
                    ->limit(60)
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('step')
                    ->badge(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('document_type')
                    ->placeholder('-'),
                TextColumn::make('review_status')
                    ->badge(),
                TextColumn::make('parent_analysis_id')
                    ->label('Re-run из')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('step')
                    ->options(collect(AnalysisStep::cases())->pluck('value', 'value')->all()),
                SelectFilter::make('status')
                    ->options(collect(AnalysisStatus::cases())->pluck('value', 'value')->all()),
                SelectFilter::make('review_status')
                    ->options(collect(ReviewStatus::cases())->pluck('value', 'value')->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                //
            ]);
    }
}
