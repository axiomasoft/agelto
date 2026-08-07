<?php

namespace App\Filament\Resources\Procurement\Crawler\Runs\Pages;

use App\Enums\Procurement\Crawler\RunStatus;
use App\Exceptions\Procurement\OperationFailed;
use App\Filament\Resources\Procurement\Crawler\Runs\RunResource;
use App\Guards\Admin\Permissions\RunPermission;
use App\Models\Procurement\Crawler\Run;
use App\Services\Procurement\Crawler\RequestRunStopAction;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

class ViewRun extends ViewRecord
{
    protected static string $resource = RunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->pauseAction(),
            $this->cancelAction(),
        ];
    }

    /**
     * Право — строка ability `RunPermission::Update` (канон `ViewFixture`); guard видимости —
     * И здесь (устаревшая карточка не показывает кликабельную кнопку), И внутри
     * `RequestRunStopAction` (гонка двух вкладок).
     */
    private function pauseAction(): Action
    {
        return Action::make('pause')
            ->label('Остановить')
            ->color('warning')
            ->visible(fn (Run $record): bool => $this->canManage() && $this->isStoppable($record))
            ->action(fn (Run $record) => $this->requestStop(
                $record,
                fn (RequestRunStopAction $action) => $action->pause($record, Auth::id()),
            ));
    }

    private function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Отменить')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (Run $record): bool => $this->canManage() && $this->isStoppable($record))
            ->action(fn (Run $record) => $this->requestStop(
                $record,
                fn (RequestRunStopAction $action) => $action->cancel($record, Auth::id()),
            ));
    }

    private function requestStop(Run $record, callable $call): void
    {
        if (! $this->canManage()) {
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

    private function canManage(): bool
    {
        return Auth::user()?->hasPermission(RunPermission::Update) ?? false;
    }

    private function isStoppable(Run $record): bool
    {
        return in_array($record->status, [RunStatus::Running, RunStatus::Paused], strict: true);
    }
}
