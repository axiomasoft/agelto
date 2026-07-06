<?php

namespace App\Filament\Resources\Procurement\Procurements\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProcurementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('uuid')
                    ->label('ИД')
                    ->searchable()
                    ->limit(18),
                TextColumn::make('registry.name')
                    ->label('Реестр')
                    ->searchable(),
                TextColumn::make('initial_price')
                    ->label('НМЦК')
                    ->money(fn ($record) => $record->currency)
                    ->sortable(),
                TextColumn::make('published_at')
                    ->label('Опубликовано')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('bidding_ends_at')
                    ->label('Приём до')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('revision_count')
                    ->label('Редакций')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('detail_failed')
                    ->label('Ошибка деталей')
                    ->boolean(),
                TextColumn::make('last_seen_at')
                    ->label('Последний контакт')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
