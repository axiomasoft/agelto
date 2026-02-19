<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\PromptRule\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Platform\Dto\PromtRule\Group;
use Platform\Models\Promt\PromptRule;

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
                TextColumn::make('group')
                    ->label('Группа')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn (Group|string $state): string => $state instanceof Group ? $state->label() : (Group::tryFrom($state)?->label() ?? $state)),
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
                SelectFilter::make('group')
                    ->label('Группа')
                    ->options(Group::options()),
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
