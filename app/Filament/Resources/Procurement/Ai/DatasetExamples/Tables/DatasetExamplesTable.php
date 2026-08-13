<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\DatasetExamples\Tables;

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Filament\Support\LlmPresentation;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Models\Procurement\Ai\DatasetExample;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DatasetExamplesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('fixture.code')
                    ->label('Код эталона')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('documentVersion.document.title')
                    ->label('Документ')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('task')
                    ->label('Задача')
                    ->badge(),
                TextColumn::make('document_type')
                    ->label('Тип документа')
                    ->badge(),
                TextColumn::make('source')
                    ->label('Источник')
                    ->badge(),
                TextColumn::make('version')
                    ->label('Версия')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('verifiedBy.name')
                    ->label('Проверил')
                    ->placeholder('—'),
                TextColumn::make('verified_at')
                    ->label('Проверено')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('task')
                    ->label('Задача')
                    ->options(collect(AnalysisStep::cases())->pluck('value', 'value')->all()),
                SelectFilter::make('document_type')
                    ->label('Тип документа')
                    ->options(LlmPresentation::documentTypeOptions()),
                SelectFilter::make('source')
                    ->label('Источник')
                    ->options(fn (): array => DatasetExample::query()
                        ->distinct()
                        ->orderBy('source')
                        ->pluck('source', 'source')
                        ->all()),
                SelectFilter::make('fixture_id')
                    ->label('Есть эталон-фикстура')
                    ->options(['yes' => 'Да', 'no' => 'Нет'])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'yes' => $query->whereNotNull('fixture_id'),
                        'no' => $query->whereNull('fixture_id'),
                        default => $query,
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                // Read-only immutable snapshots: no mutation actions.
            ]);
    }
}
