<?php

declare(strict_types=1);

namespace App\Filament\Resources\PromptRule\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use App\Dto\Prompt\Step;
use App\Models\Prompt\PromptRule;

final class PromptRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordTitle(fn (PromptRule $record): string => $record->key)
            ->columns([
                TextColumn::make('key')
                    ->label('Ключ')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('step')
                    ->label('Шаг')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (Step|string $state): string => $state instanceof Step ? $state->label() : (Step::tryFrom($state)?->label() ?? $state)),
                TextColumn::make('priority')
                    ->label('Приоритет')
                    ->sortable(),
                TextColumn::make('content')
                    ->label('Содержание')
                    ->markdown()
                    ->limit(60)
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('is_active')
                    ->label('Активно')
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('step')
                    ->label('Шаг')
                    ->options(Step::options()),
                SelectFilter::make('is_active')
                    ->label('Активно')
                    ->options([
                        true => 'Да',
                        false => 'Нет',
                    ]),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('priority', 'asc');
    }
}
