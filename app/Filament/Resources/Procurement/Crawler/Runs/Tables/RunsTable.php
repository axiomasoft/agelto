<?php

namespace App\Filament\Resources\Procurement\Crawler\Runs\Tables;

use App\Enums\Procurement\Crawler\RunStatus;
use App\Exceptions\Procurement\OperationFailed;
use App\Guards\Admin\Permissions\RunPermission;
use App\Models\Procurement\Crawler\Run;
use App\Services\Procurement\Crawler\RequestRunStopAction;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class RunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('crawler.name')
                    ->searchable(),
                TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('finished_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('last_heartbeat_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('attempts')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('last_error_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('next_retry_at')
                    ->dateTime()
                    ->sortable(),
                IconColumn::make('failed_permanently')
                    ->boolean(),
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
                //
            ])
            // Мутации — только остановка/отмена уже идущего прогона (P3.4), не create/edit/delete.
            ->recordActions([
                ViewAction::make(),
                self::pauseAction(),
                self::cancelAction(),
            ]);
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
