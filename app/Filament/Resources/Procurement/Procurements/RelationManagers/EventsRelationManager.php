<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Procurements\RelationManagers;

use App\Enums\Procurement\ProcurementEventType;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only лента канонических событий закупки. Она существует отдельной вкладкой,
 * потому что оператору нужен фильтруемый журнал, а не неизменяемая секция карточки; мутации
 * принадлежат конвейеру и намеренно не представлены здесь.
 */
class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('occurred_at')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('revision')->reorder())
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Когда')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('event_type')
                    ->label('Событие')
                    ->badge()
                    ->formatStateUsing(fn (ProcurementEventType $state): string => (string) str($state->value)->replace('_', ' ')->ucfirst()),
                TextColumn::make('field')
                    ->label('Поле')
                    ->placeholder('—')
                    ->visibleFrom('md'),
                TextColumn::make('old_value')
                    ->label('Было')
                    ->placeholder('—')
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->visibleFrom('md'),
                TextColumn::make('new_value')
                    ->label('Стало')
                    ->placeholder('—')
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (?string $state): ?string => $state),
                TextColumn::make('revision.revision_number')
                    ->label('Редакция')
                    ->placeholder('—')
                    ->visibleFrom('md'),
            ])
            ->filters([
                SelectFilter::make('event_type')
                    ->label('Тип события')
                    ->options(collect(ProcurementEventType::cases())->mapWithKeys(
                        fn (ProcurementEventType $type): array => [$type->value => (string) str($type->value)->replace('_', ' ')->ucfirst()],
                    )->all()),
                self::historyPeriodFilter('occurred_at'),
                Filter::make('occurred_at')
                    ->label('Окно дат')
                    ->schema([
                        DatePicker::make('from')->label('С'),
                        DatePicker::make('until')->label('По'),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, static fn (Builder $query, mixed $date): Builder => is_string($date) ? $query->whereDate('occurred_at', '>=', $date) : $query)
                        ->when($data['until'] ?? null, static fn (Builder $query, mixed $date): Builder => is_string($date) ? $query->whereDate('occurred_at', '<=', $date) : $query)),
            ])
            ->defaultSort('occurred_at', 'desc');
    }

    private static function historyPeriodFilter(string $column): TernaryFilter
    {
        return TernaryFilter::make('all_time')
            ->label('Период')
            ->trueLabel('За всё время')
            ->falseLabel('Последние 90 дней')
            ->default(false)
            ->queries(
                true: static fn (Builder $query): Builder => $query,
                false: static fn (Builder $query): Builder => $query->where($column, '>=', now()->subDays(90)),
            );
    }
}
