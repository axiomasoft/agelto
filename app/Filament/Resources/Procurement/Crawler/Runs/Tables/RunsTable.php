<?php

namespace App\Filament\Resources\Procurement\Crawler\Runs\Tables;

use App\Enums\Procurement\Crawler\RunStatus;
use App\Exceptions\Procurement\OperationFailed;
use App\Guards\Admin\Permissions\RunPermission;
use App\Models\Procurement\Crawler\Run;
use App\Services\Procurement\Crawler\RequestRunStopAction;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class RunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(static fn (RunStatus $state): string => self::statusColor($state)),
                TextColumn::make('crawler.name')
                    ->label('Профиль')
                    ->searchable()
                    ->wrapHeader(),
                TextColumn::make('started_at')
                    ->label('Начат')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('finished_at')
                    ->label('Завершён')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable()
                    ->visibleFrom('md'),
                TextColumn::make('duration')
                    ->label('Длительность')
                    ->state(fn (Run $record): ?string => $record->started_at === null
                        ? null
                        : $record->started_at->diffForHumans($record->finished_at ?? now(), syntax: CarbonInterface::DIFF_ABSOLUTE))
                    ->placeholder('—')
                    ->visibleFrom('md'),
                TextColumn::make('fetches_count')
                    ->label('Блоков')
                    ->numeric()
                    ->sortable()
                    ->visibleFrom('md'),
                TextColumn::make('fetches_rows_new_sum')
                    ->label('Новых строк')
                    ->numeric()
                    ->placeholder('0')
                    ->sortable()
                    ->visibleFrom('md'),
                TextColumn::make('fetches_rows_updated_sum')
                    ->label('Обновлённых строк')
                    ->numeric()
                    ->placeholder('0')
                    ->sortable()
                    ->visibleFrom('md'),
                TextColumn::make('stop_reason')
                    ->label('Причина остановки')
                    ->state(fn (Run $record): ?string => $record->stats?->get('stop_reason'))
                    ->placeholder('—')
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->visibleFrom('md'),
                IconColumn::make('cap_hit')
                    ->label('Возможен пропуск')
                    ->state(fn (Run $record): bool => (bool) $record->stats?->get('cap_hit'))
                    ->boolean()
                    ->visibleFrom('md'),
                TextColumn::make('attempts')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('failed_permanently')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('started_at', 'desc')
            ->filters([
                SelectFilter::make('crawler_id')
                    ->label('Профиль')
                    ->relationship('crawler', 'name'),
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(collect(RunStatus::cases())->pluck('value', 'value')->all()),
                self::startedAtRangeFilter(),
            ])
            // Мутации — только остановка/отмена уже идущего прогона (P3.4), не create/edit/delete.
            ->recordActions([
                ViewAction::make(),
                self::pauseAction(),
                self::cancelAction(),
            ]);
    }

    private static function startedAtRangeFilter(): Filter
    {
        return Filter::make('started_at')
            ->label('Окно старта')
            ->schema([
                DatePicker::make('started_from')->label('С'),
                DatePicker::make('started_until')->label('По'),
            ])
            ->query(static function (Builder $query, array $data): Builder {
                return $query
                    ->when(
                        $data['started_from'] ?? null,
                        fn (Builder $query, string $date): Builder => $query->whereDate('started_at', '>=', $date),
                    )
                    ->when(
                        $data['started_until'] ?? null,
                        fn (Builder $query, string $date): Builder => $query->whereDate('started_at', '<=', $date),
                    );
            });
    }

    private static function statusColor(RunStatus $status): string
    {
        return match ($status) {
            RunStatus::Running => 'info',
            RunStatus::Paused => 'warning',
            RunStatus::Completed => 'success',
            RunStatus::Failed, RunStatus::Cancelled => 'danger',
        };
    }

    /**
     * Право — строка ability `RunPermission::Update` (канон `ViewFixture`). Видимость —
     * ТОЛЬКО `running`/`paused` (D26): на терминальном прогоне останавливать нечего.
     */
    private static function pauseAction(): Action
    {
        return Action::make('pause')
            ->label('Остановить')
            ->color('warning')
            ->visible(fn (Run $record): bool => self::canManage() && self::isStoppable($record))
            ->action(fn (Run $record) => self::requestStop(
                $record,
                fn (RequestRunStopAction $action) => $action->pause($record, Auth::id()),
            ));
    }

    private static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Отменить')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (Run $record): bool => self::canManage() && self::isStoppable($record))
            ->action(fn (Run $record) => self::requestStop(
                $record,
                fn (RequestRunStopAction $action) => $action->cancel($record, Auth::id()),
            ));
    }

    /**
     * Guard — И в `visible()`, И внутри `RequestRunStopAction` (гонка двух вкладок).
     * Остановка кооперативна (D28): успех означает «запрос записан», не «прогон уже стоит» —
     * текст уведомления обязан называть отложенность явно (Implementation Rules п. 4).
     */
    private static function requestStop(Run $record, callable $call): void
    {
        if (! self::canManage()) {
            Notification::make()->title('Недостаточно прав для управления прогоном')->danger()->send();

            return;
        }

        try {
            $call(app(RequestRunStopAction::class));
        } catch (OperationFailed $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title('Остановка запрошена — прогон завершится на текущем блоке')
            ->success()
            ->send();
    }

    private static function canManage(): bool
    {
        return Auth::user()?->hasPermission(RunPermission::Update) ?? false;
    }

    private static function isStoppable(Run $record): bool
    {
        return in_array($record->status, [RunStatus::Running, RunStatus::Paused], strict: true);
    }
}
