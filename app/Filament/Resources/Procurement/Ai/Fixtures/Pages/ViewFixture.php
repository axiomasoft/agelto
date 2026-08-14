<?php

namespace App\Filament\Resources\Procurement\Ai\Fixtures\Pages;

use App\Exceptions\Procurement\OperationFailed;
use App\Filament\Resources\Procurement\Ai\Fixtures\FixtureResource;
use App\Guards\Admin\Permissions\AnalysisPermission;
use App\Models\Procurement\Ai\Fixture;
use App\Repositories\Procurement\Ai\AnalysisRunStoreRepository;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use App\Services\Procurement\Fixture\RunFixtureAnalysisAction;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

class ViewFixture extends ViewRecord
{
    protected static string $resource = FixtureResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->runAction(),
            EditAction::make(),
        ];
    }

    /**
     * Право — `AnalysisPermission::Create` (D44), проверенное СТРОКОЙ ability через
     * `hasPermission()` (`Gate::before` AzGuard резолвит её без модели): `->authorize('create')`
     * резолвился бы на модель ресурса эталонов и дал бы `fixture.create` (Implementation Rule 8).
     * Guard активного прогона стоит И здесь (устаревшая карточка не показывает кликабельную
     * кнопку), И внутри `RunFixtureAnalysisAction::run()` (Implementation Rule 7).
     */
    private function runAction(): Action
    {
        return Action::make('run')
            ->label('Прогнать')
            ->color('primary')
            ->requiresConfirmation()
            ->visible(fn (Fixture $record): bool => $this->canRun() && ! $this->hasActiveRun($record))
            ->action(function (Fixture $record): void {
                if (! $this->canRun()) {
                    Notification::make()->title('Недостаточно прав для запуска прогона')->danger()->send();

                    return;
                }

                try {
                    $userId = Auth::id();
                    app(RunFixtureAnalysisAction::class)->run($record, is_int($userId) ? $userId : null);
                } catch (OperationFailed $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Поставлено в очередь')->success()->send();
            });
    }

    private function canRun(): bool
    {
        return Auth::user()?->hasPermission(AnalysisPermission::Create) ?? false;
    }

    private function hasActiveRun(Fixture $record): bool
    {
        $document = $record->document;

        if ($document === null) {
            return false;
        }

        $version = app(DocumentStoreRepository::class)->findVersionByHash($document->id, $record->sha256);

        return $version !== null && app(AnalysisRunStoreRepository::class)->findActive($version->id) !== null;
    }
}
