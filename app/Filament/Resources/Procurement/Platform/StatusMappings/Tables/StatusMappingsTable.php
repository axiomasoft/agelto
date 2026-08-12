<?php

namespace App\Filament\Resources\Procurement\Platform\StatusMappings\Tables;

use App\Enums\Procurement\ProcurementStatus;
use App\Enums\Procurement\StatusMappingApplicability;
use App\Models\Procurement\Platform\Platform;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class StatusMappingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('platform.name')
                    ->label('Площадка')
                    ->placeholder('Через реестр'),
                TextColumn::make('registry.code')
                    ->label('Реестр')
                    ->searchable(),
                TextColumn::make('applicability')
                    ->label('Применимость')
                    ->badge(),
                TextColumn::make('source_stage')
                    ->label('Этап площадки')
                    ->searchable(),
                TextColumn::make('canonical_status')
                    ->label('Канонический статус')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('platform')
                    ->label('Площадка')
                    ->options(Platform::query()->pluck('name', 'id'))
                    ->query(function (Builder $query, array $data): Builder {
                        $platformId = $data['value'] ?? null;

                        if ($platformId === null) {
                            return $query;
                        }

                        return $query->where(function (Builder $query) use ($platformId): void {
                            $query->where('platform_id', $platformId)
                                ->orWhereHas('registry', fn (Builder $query): Builder => $query->where('platform_id', $platformId));
                        });
                    }),
                SelectFilter::make('registry_id')
                    ->label('Реестр')
                    ->relationship('registry', 'name'),
                SelectFilter::make('applicability')
                    ->label('Применимость')
                    ->options(StatusMappingApplicability::class)
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            StatusMappingApplicability::Platform->value => $query->whereNotNull('platform_id'),
                            StatusMappingApplicability::Registry->value => $query->whereNotNull('registry_id'),
                            default => $query,
                        };
                    }),
                SelectFilter::make('canonical_status')
                    ->label('Канонический статус')
                    ->options(ProcurementStatus::class),
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
