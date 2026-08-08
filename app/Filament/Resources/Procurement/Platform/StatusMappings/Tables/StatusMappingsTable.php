<?php

namespace App\Filament\Resources\Procurement\Platform\StatusMappings\Tables;

use App\Enums\Procurement\ProcurementStatus;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class StatusMappingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('registry.code')
                    ->label('Реестр')
                    ->searchable(),
                TextColumn::make('source_stage')
                    ->label('Этап площадки')
                    ->searchable(),
                TextColumn::make('canonical_status')
                    ->label('Канонический статус')
                    ->badge(),
            ])
            ->filters([
                Filter::make('only_unknown')
                    ->label('Только unknown')
                    ->query(fn (Builder $query): Builder => $query->where(
                        'canonical_status',
                        ProcurementStatus::Unknown->value,
                    )),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
