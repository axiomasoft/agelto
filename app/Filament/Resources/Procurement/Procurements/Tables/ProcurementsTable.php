<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Procurements\Tables;

use App\Filament\Resources\Procurement\Procurements\ProcurementResource;
use App\Models\Procurement\Procurement;
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
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('registry.name')
                    ->label('Реестр')
                    ->searchable()
                    ->wrapHeader()
                    ->visibleFrom('md'),
                TextColumn::make('initial_price')
                    ->label('НМЦК')
                    ->money(fn (Procurement $record): ?string => $record->currency)
                    ->sortable(),
                TextColumn::make('published_at')
                    ->label('Опубликовано')
                    ->dateTime()
                    ->sortable()
                    ->visibleFrom('md'),
                TextColumn::make('bidding_ends_at')
                    ->label('Приём до')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('revision_count')
                    ->label('Редакций')
                    ->numeric()
                    ->sortable()
                    ->visibleFrom('md'),
                TextColumn::make('detail_work_state')->label('Получение карточки')->placeholder('Не запланировано'),
                TextColumn::make('last_seen_at')
                    ->label('Последний контакт')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('view_link')
                    ->label('')
                    ->state('Просмотреть')
                    ->url(fn (Procurement $record): string => ProcurementResource::getUrl('view', ['record' => $record])),
            ])
            ->filters([
                //
            ])
            // The resource query already filters viewable rows; the destination rechecks access.
            ->recordUrl(fn (Procurement $record): string => ProcurementResource::getUrl('view', ['record' => $record]));
    }
}
