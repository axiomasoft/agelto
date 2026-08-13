<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Health\PlatformChecks\Tables;

use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Enums\Procurement\Health\PlatformCheckVerdict;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PlatformChecksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('platform.name')->label('Площадка')->searchable()->wrapHeader(),
                TextColumn::make('check_kind')->label('Проверка')->badge(),
                TextColumn::make('verdict')
                    ->label('Вердикт')
                    ->badge()
                    ->color(static fn (PlatformCheckVerdict $state): string => $state->color()),
                TextColumn::make('sample_size')->label('Выборка')->numeric()->sortable()->visibleFrom('md'),
                TextColumn::make('requests_used')->label('Запросы')->numeric()->sortable()->visibleFrom('md'),
                TextColumn::make('window_from')->label('Окно с')->dateTime()->placeholder('—')->visibleFrom('md'),
                TextColumn::make('window_to')->label('Окно по')->dateTime()->placeholder('—')->visibleFrom('md'),
                TextColumn::make('checked_at')->label('Проверено')->dateTime()->sortable(),
            ])
            ->defaultSort('checked_at', 'desc')
            ->filters([
                SelectFilter::make('platform_id')->label('Площадка')->relationship('platform', 'name'),
                SelectFilter::make('check_kind')->label('Проверка')->options(self::enumOptions(PlatformCheckKind::cases())),
                SelectFilter::make('verdict')->label('Вердикт')->options(self::enumOptions(PlatformCheckVerdict::cases())),
                self::checkedAtRangeFilter(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    private static function checkedAtRangeFilter(): Filter
    {
        return Filter::make('checked_at')
            ->label('Время проверки')
            ->schema([
                DatePicker::make('from')->label('С'),
                DatePicker::make('until')->label('По'),
            ])
            ->query(static fn (Builder $query, array $data): Builder => $query
                ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('checked_at', '>=', $date))
                ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('checked_at', '<=', $date)));
    }

    /** @param array<int, PlatformCheckKind|PlatformCheckVerdict> $cases */
    private static function enumOptions(array $cases): array
    {
        return collect($cases)->pluck('value', 'value')->all();
    }
}
