<?php

namespace App\Filament\Resources\Procurement\Ai\Analyses\Pages;

use App\Enums\Procurement\AnalysisStatus;
use App\Enums\Procurement\CorrectionReason;
use App\Enums\Procurement\ReviewStatus;
use App\Filament\Pages\FinalPromptPage;
use App\Filament\Resources\Procurement\Ai\Analyses\AnalysisResource;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\Correction;
use App\Repositories\Procurement\AnalysisStoreRepository;
use App\Services\Procurement\Analyzers\Estimate\GoldExampleRecorder;
use App\Services\Procurement\Extraction\RerunAnalysisAction;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

/**
 * Операторский цикл (P9.2, Intent п.3): «Проверено», «Отклонить с коррекцией», «Перезапустить
 * разбор» — минимальные действия на карточке анализа (Scope Included — «полноценная витрина —
 * Фаза 7 роадмапа»).
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
     * Вход на витрину финального промта (P5.5, D46) из карточки анализа — второй, помимо строки
     * прогона, вход, названный Scope Included P5.5.
     */
    private function finalPromptAction(): Action
    {
        return Action::make('finalPrompt')
            ->label('Финальный промт')
            ->color('gray')
            ->url(fn (Analysis $record): string => FinalPromptPage::getUrl(['run' => $record->analysis_run_id]))
            ->openUrlInNewTab();
    }

    /**
     * «Проверено» — доступна только при `status=succeeded` (Code Guidance) И `review_status`
     * ещё не `approved`: без второго условия кнопка остаётся видимой после одобрения (ревью
     * P9.3, HIGH) и повторный клик/сабмит дублирует immutable gold-версию
     * (`GoldExampleRecorder`). `review_status !== Approved` в действии — тот же guard ещё раз,
     * на случай устаревшего `$record` в момент клика (visible() рендерится раньше, чем
     * обрабатывается action) — идемпотентность без изменения схемы `prc_ai_dataset_examples`
     * (в ней нет `analysis_id`, docblock `GoldExampleRecorder`).
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
                if ($record->review_status === ReviewStatus::Approved) {
                    Notification::make()->title('Анализ уже отмечен проверенным')->warning()->send();

                    return;
                }

                app(AnalysisStoreRepository::class)->update($record, [
                    'review_status' => ReviewStatus::Approved,
                    'reviewed_by' => Auth::id(),
                    'reviewed_at' => now(),
                ]);

                // P9.3: approve — единственная точка входа gold-петли (`GoldExampleRecorder`
                // читает уже обновлённые `reviewed_by`/`reviewed_at` того же объекта `$record`).
                app(GoldExampleRecorder::class)->record($record);

                Notification::make()->title('Анализ отмечен проверенным')->success()->send();
            });
    }

    /**
     * Форма фиксирует структурированную коррекцию (`prc_ai_corrections`, P9.1) — `field_path`
     * необязателен (весь документ, если не указан конкретный JSONPath); `hint` заполняется
     * позже (переформулировка для промта — вне этого action'а, `hint ?? content` на композиции).
     *
     * `prc_ai_corrections.document_type` — `NOT NULL` (§3.2 root/document-type-axis.md), а
     * `prc_ai_analyses.document_type` остаётся nullable (§3.1: скрининг ещё не отработал) —
     * коррекция над анализом без вердикта скрининга недоступна: молчаливая подстановка
     * `unknown` соврала бы про вердикт (Implementation Rules P1.4 п. 12).
     */
    private function rejectWithCorrectionAction(): Action
    {
        return Action::make('rejectWithCorrection')
            ->label('Отклонить с коррекцией')
            ->color('danger')
            ->authorize('update')
            ->visible(fn (Analysis $record): bool => $record->document_type !== null)
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
                if ($record->document_type === null) {
                    Notification::make()->title('Нельзя создать коррекцию: скрининг ещё не определил тип документа')->danger()->send();

                    return;
                }

                Correction::query()->create([
                    'analysis_id' => $record->id,
                    'document_type' => $record->document_type,
                    'field_path' => $data['field_path'] !== '' ? $data['field_path'] : null,
                    'reason' => $data['reason'],
                    'content' => $data['content'],
                    'is_reusable' => (bool) ($data['is_reusable'] ?? false),
                    'created_by' => Auth::id(),
                ]);

                app(AnalysisStoreRepository::class)->update($record, [
                    'review_status' => ReviewStatus::Rejected,
                    'reviewed_by' => Auth::id(),
                    'reviewed_at' => now(),
                    'operator_comment' => $data['content'],
                ]);

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
                $new = app(RerunAnalysisAction::class)->run($record);

                Notification::make()->title('Повторный разбор запущен')->success()->send();

                return redirect(AnalysisResource::getUrl('view', ['record' => $new]));
            });
    }
}
