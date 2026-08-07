<?php

namespace App\Filament\Resources\Procurement\Crawler\Crawlers\Tables;

use App\Enums\Procurement\Crawler\RunStatus;
use App\Exceptions\Procurement\OperationFailed;
use App\Guards\Admin\Permissions\RunPermission;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Run;
use App\Services\Procurement\Crawler\StartCrawlerRunAction;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class CrawlersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('registry.name')
                    ->searchable(),
                TextColumn::make('mode')
                    ->badge(),
                TextColumn::make('schedule')
                    ->placeholder('—'),
                IconColumn::make('is_active')
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
            ->recordActions([
                self::startAction(),
                self::restartAction(),
                self::resumeAction(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Право — строка ability `RunPermission::Update` через `hasPermission()` (канон
     * `ViewFixture`): `->authorize('update')` резолвился бы на модель ресурса КРАУЛЕРОВ и дал бы
     * `crawler.update` — право не того ресурса (управление ПРОГОНОМ, не профилем).
     */
    private static function startAction(): Action
    {
        return Action::make('start')
            ->label('Запустить')
            ->color('primary')
            ->visible(fn (Crawler $record): bool => self::canManage()
                && self::activeRun($record) === null
                && self::pausedRun($record) === null)
            ->action(fn (Crawler $record) => self::dispatch(
                $record,
                fn (StartCrawlerRunAction $action) => $action->start($record),
            ));
    }

    private static function restartAction(): Action
    {
        return Action::make('restart')
            ->label('Запустить с начала')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (Crawler $record): bool => self::canManage() && self::activeRun($record) === null)
            ->action(fn (Crawler $record) => self::dispatch(
                $record,
                fn (StartCrawlerRunAction $action) => $action->restart($record),
            ));
    }

    private static function resumeAction(): Action
    {
        return Action::make('resume')
            ->label('Продолжить')
            ->color('primary')
            ->visible(fn (Crawler $record): bool => self::canManage()
                && self::activeRun($record) === null
                && self::pausedRun($record) !== null)
            ->action(fn (Crawler $record) => self::dispatch(
                $record,
                fn (StartCrawlerRunAction $action) => $action->resume($record),
            ));
    }

    /**
     * Guard активного прогона стоит И в `visible()` (устаревшая страница не показывает
     * кликабельную кнопку), И внутри `StartCrawlerRunAction` (гонка двух вкладок,
     * Implementation Rules п. 2).
     */
    private static function dispatch(Crawler $record, callable $call): void
    {
        if (! self::canManage()) {
            Notification::make()->title('Недостаточно прав для управления прогоном')->danger()->send();

            return;
        }

        try {
            $call(app(StartCrawlerRunAction::class));
        } catch (OperationFailed $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Поставлено в очередь')->success()->send();
    }

    private static function canManage(): bool
    {
        return Auth::user()?->hasPermission(RunPermission::Update) ?? false;
    }

    private static function activeRun(Crawler $record): ?Run
    {
        return Run::query()->where('crawler_id', $record->id)->where('status', RunStatus::Running)->first();
    }

    private static function pausedRun(Crawler $record): ?Run
    {
        return Run::query()->where('crawler_id', $record->id)->where('status', RunStatus::Paused)->first();
    }
}
