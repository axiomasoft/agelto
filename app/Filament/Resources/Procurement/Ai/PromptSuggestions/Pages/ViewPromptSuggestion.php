<?php

namespace App\Filament\Resources\Procurement\Ai\PromptSuggestions\Pages;

use App\Enums\Procurement\Ai\PromptSuggestionStatus;
use App\Exceptions\Procurement\OperationFailed;
use App\Filament\Resources\Procurement\Ai\PromptSuggestions\PromptSuggestionResource;
use App\Guards\Admin\Permissions\PromptSuggestionPermission;
use App\Models\Procurement\Ai\PromptSuggestion;
use App\Services\Procurement\Tuning\PromptSuggestionDecider;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

/**
 * Карточка предложения: человек РЕШАЕТ («Одобрить»/«Отклонить»), файл промта пишет команда
 * `ai:prompts:apply` (D47). Ни одно действие этой страницы не касается каталога промтов — апрув
 * меняет только статус и аудит решения.
 *
 * Право решения — `PromptSuggestionPermission::Update` (Implementation Rule 5): оператору оно не
 * выдаётся, потому что текст системной инструкции меняет поведение модели на ВСЕХ документах.
 * Guard стоит дважды — в `visible()` (кнопки нет) и ВНУТРИ действия (устаревшая карточка: клик
 * до перерендера), тот же канон, что `ViewFixture::runAction()`.
 */
class ViewPromptSuggestion extends ViewRecord
{
    protected static string $resource = PromptSuggestionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->approveAction(),
            $this->rejectAction(),
        ];
    }

    private function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Одобрить')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('Одобрение НЕ меняет файл промта: патч применяет команда ai:prompts:apply.')
            ->visible(fn (PromptSuggestion $record): bool => $this->canDecide() && $this->isPending($record))
            ->form([
                Textarea::make('decision_note')
                    ->label('Заметка (необязательно)')
                    ->rows(3),
            ])
            ->action(function (PromptSuggestion $record, array $data): void {
                $this->decide(
                    fn (int $decidedBy) => app(PromptSuggestionDecider::class)->approve(
                        suggestion: $record,
                        decidedBy: $decidedBy,
                        note: self::note($data),
                    ),
                    'Предложение одобрено — примените патч командой ai:prompts:apply',
                );
            });
    }

    private function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Отклонить')
            ->color('danger')
            ->visible(fn (PromptSuggestion $record): bool => $this->canDecide() && $this->isPending($record))
            ->form([
                Textarea::make('decision_note')
                    ->label('Причина отказа')
                    ->required()
                    ->rows(3),
            ])
            ->action(function (PromptSuggestion $record, array $data): void {
                $this->decide(
                    fn (int $decidedBy) => app(PromptSuggestionDecider::class)->reject(
                        suggestion: $record,
                        decidedBy: $decidedBy,
                        note: (string) self::note($data),
                    ),
                    'Предложение отклонено',
                );
            });
    }

    /**
     * Общая обвязка обоих решений: право → вызов сервиса → отказ сервиса как уведомление, а не
     * как «ничего не произошло». Проверка права ЗДЕСЬ, а не только в `visible()`: `Action::call()`
     * в обход Livewire-пайплайна эмулирует устаревшую карточку.
     *
     * @param  callable(int): PromptSuggestion  $decision
     */
    private function decide(callable $decision, string $successMessage): void
    {
        if (! $this->canDecide()) {
            Notification::make()->title('Недостаточно прав для решения по предложению')->danger()->send();

            return;
        }

        try {
            $decision((int) Auth::id());
        } catch (OperationFailed $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($successMessage)->success()->send();
    }

    private function canDecide(): bool
    {
        return Auth::user()?->hasPermission(PromptSuggestionPermission::Update) ?? false;
    }

    private function isPending(PromptSuggestion $suggestion): bool
    {
        return $suggestion->status === PromptSuggestionStatus::Pending;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function note(array $data): ?string
    {
        $note = $data['decision_note'] ?? null;

        return is_string($note) && $note !== '' ? $note : null;
    }
}
