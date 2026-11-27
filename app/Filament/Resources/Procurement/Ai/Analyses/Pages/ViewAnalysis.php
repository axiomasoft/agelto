<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\Analyses\Pages;

use App\Actions\Procurement\Ai\ReviewAnalysisAction;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\CorrectionReason;
use App\Enums\Procurement\Ai\ReviewStatus;
use App\Exceptions\Llm\OperationDenied;
use App\Filament\Pages\FinalPromptPage;
use App\Filament\Resources\Procurement\Ai\Analyses\AnalysisResource;
use App\Filament\Resources\Procurement\Process\Work\WorkItemResource;
use App\Models\Procurement\Ai\Analysis;
use App\Services\Procurement\Extraction\Orchestration\RerunAnalysisAction;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

/**
 * Операторские действия на карточке анализа: «Проверено», «Отклонить с коррекцией»,
 * «Перезапустить разбор».
 */
class ViewAnalysis extends ViewRecord
{
    protected static string $resource = AnalysisResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->finalPromptAction(),
            $this->approveAction(),
            $this->rejectWithCorrectionAction(),
            $this->rerunAction(),
        ];
    }

    /**
     * Вход на витрину финального промта из карточки анализа — второй, помимо строки
     * прогона.
     */
    private function finalPromptAction(): Action
    {
        return Action::make('finalPrompt')
            ->label('Финальный промт')
            ->color('gray')
            ->authorize(fn (): bool => FinalPromptPage::canAccess())
            ->visible(fn (Analysis $record): bool => $record->analysis_run_id !== null && FinalPromptPage::canAccess())
            ->url(fn (Analysis $record): ?string => $record->analysis_run_id === null ? null : FinalPromptPage::getUrl(['run' => $record->analysis_run_id]))
            ->openUrlInNewTab();
    }

    /**
     * Видимость — подсказка UI; fresh eligibility, идемпотентность и атомарный review/gold
     * принадлежат ReviewAnalysisAction под version/result locks.
     */
    private function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Проверено')
            ->color('success')
            ->authorize('update')
            ->requiresConfirmation()
            ->visible(fn (Analysis $record): bool => $record->status === AnalysisStatus::Succeeded
                && $record->review_status !== ReviewStatus::Approved)
            ->action(function (Analysis $record): void {
                $changed = app(ReviewAnalysisAction::class)->approve(analysisId: $record->id, reviewerId: (int) Auth::id());
                $record->refresh();
                if (! $changed) {
                    Notification::make()->title('Анализ уже отмечен проверенным')->warning()->send();

                    return;
                }

                Notification::make()->title('Анализ отмечен проверенным')->success()->send();
            });
    }

    /**
     * Форма фиксирует структурированную коррекцию (`prc_ai_corrections`) — `field_path`
     * необязателен (весь документ, если не указан конкретный JSONPath); `hint` заполняется
     * позже (переформулировка для промта — вне этого action'а, `hint ?? content` на композиции).
     *
     * `prc_ai_corrections.document_type` — `NOT NULL`, а
     * `prc_ai_analyses.document_type` остаётся nullable (скрининг ещё не отработал) —
     * коррекция над анализом без вердикта скрининга недоступна: молчаливая подстановка
     * `unknown` соврала бы про вердикт.
     */
    private function rejectWithCorrectionAction(): Action
    {
        return Action::make('rejectWithCorrection')
            ->label('Отклонить с коррекцией')
            ->color('danger')
            ->authorize('update')
            ->visible(fn (Analysis $record): bool => $record->status === AnalysisStatus::Succeeded
                && $record->document_type !== null)
            ->form([
                TextInput::make('field_path')
                    ->label('Поле (JSONPath)')
                    ->placeholder('Оставьте пустым — коррекция всего документа')
                    ->maxLength(255),
                Select::make('reason')
                    ->label('Причина')
                    ->required()
                    ->options(collect(CorrectionReason::cases())->mapWithKeys(
                        static fn (CorrectionReason $reason): array => [$reason->value => $reason->value],
                    )->all()),
                Textarea::make('content')
                    ->label('Что не так')
                    ->required()
                    ->rows(4),
                Toggle::make('is_reusable')
                    ->label('Применять ко всем документам этого типа'),
            ])
            ->action(function (Analysis $record, array $data): void {
                $changed = app(ReviewAnalysisAction::class)->rejectWithCorrection(
                    analysisId: $record->id,
                    reviewerId: (int) Auth::id(),
                    correction: [
                        'field_path' => $data['field_path'] ?? null,
                        'reason' => $data['reason'],
                        'content' => $data['content'],
                        'is_reusable' => $data['is_reusable'] ?? false,
                    ],
                );
                $record->refresh();
                if (! $changed) {
                    Notification::make()->title('Для коррекции нужен успешно завершённый анализ с определённым типом документа')->danger()->send();

                    return;
                }

                Notification::make()->title('Коррекция сохранена')->success()->send();
            });
    }

    /**
     * Доступна для терминальных статусов (успех/провал) — `pending`/`running` уже обрабатываются
     * штатным job'ом, повторный диспатч поверх них бессмыслен.
     */
    private function rerunAction(): Action
    {
        return Action::make('rerun')
            ->label('Перезапустить разбор')
            ->color('warning')
            ->authorize('update')
            ->requiresConfirmation()
            ->visible(fn (Analysis $record): bool => in_array($record->status, [AnalysisStatus::Succeeded, AnalysisStatus::Failed], strict: true))
            ->action(function (Analysis $record) {
                try {
                    $new = app(RerunAnalysisAction::class)->run($record, actorId: is_int(auth()->id()) ? auth()->id() : null);
                } catch (OperationDenied|\InvalidArgumentException) {
                    Notification::make()->title('Повторный разбор недоступен: проверьте текущую работу и профиль анализа.')->warning()->send();

                    return null;
                }

                Notification::make()->title('Повторный разбор запущен')->success()->send();

                return redirect(WorkItemResource::getUrl(name: 'index', parameters: ['search' => $new->value->value]));
            });
    }
}
