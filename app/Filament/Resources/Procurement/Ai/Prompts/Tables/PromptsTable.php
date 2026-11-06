<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\Prompts\Tables;

use App\Models\Llm\Prompt;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PromptsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('document_type')
                    ->label('Тип документа')
                    ->sortable()
                    ->wrapHeader(),
                TextColumn::make('key')
                    ->label('Ключ')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('step')
                    ->label('Шаг')
                    ->searchable()
                    ->sortable()
                    ->visibleFrom('md'),
                TextColumn::make('version')
                    ->label('Версия')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('priority')
                    ->label('Приоритет')
                    ->numeric()
                    ->sortable()
                    ->visibleFrom('md'),
                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean(),
                IconColumn::make('is_protected')
                    ->label('Канон')
                    ->boolean()
                    ->tooltip('Канонический промт из git — правится через репозиторий и db:seed, в админке read-only'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('document_type')
                    ->label('Тип документа')
                    ->options(static fn (): array => Prompt::query()
                        ->distinct()
                        ->orderBy('document_type')
                        ->pluck('document_type', 'document_type')
                        ->all())
                    ->searchable(),
                SelectFilter::make('key')
                    ->label('Ключ')
                    ->options(static fn (): array => Prompt::query()
                        ->distinct()
                        ->orderBy('key')
                        ->pluck('key', 'key')
                        ->all())
                    ->searchable(),
                SelectFilter::make('step')
                    ->label('Шаг')
                    ->options(static fn (): array => Prompt::query()
                        ->distinct()
                        ->orderBy('step')
                        ->pluck('step', 'step')
                        ->all())
                    ->searchable(),
                TernaryFilter::make('is_active')
                    ->label('Активность'),
                TernaryFilter::make('is_protected')
                    ->label('Канон'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorizeIndividualRecords('delete'),
                ]),
            ]);
    }
}
