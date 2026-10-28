<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Process\Work\Tables;

use App\Dto\Procurement\Process\OperatorCommandResult;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Procurement\Process\WorkState;
use App\Guards\Admin\Permissions\ProcessControlPermission;
use App\Models\Procurement\Process\WorkItem;
use App\Models\User;
use App\Services\Procurement\Process\ProcessOperatorCommands;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Ramsey\Uuid\Uuid;

final class WorkItemsTable
{
    private const string FAULT_LIMIT = 'handler_fault_limit';

    private const array STATES = [
        'ready' => 'Готова', 'admitted' => 'Допущена', 'running' => 'Выполняется', 'deferred' => 'Отложена', 'blocked' => 'Заблокирована',
        'succeeded' => 'Завершена', 'failed_item' => 'Отказ по объекту', 'cancelled' => 'Отменена', 'superseded' => 'Заменена',
    ];

    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('updated_at')->label('Изменена')->dateTime()->sortable(),
            TextColumn::make('stage')->label('Стадия')->formatStateUsing(fn (mixed $state): string => $state instanceof StageCode ? $state->value : '—')->searchable(),
            TextColumn::make('state')->label('Состояние')->badge()
                ->formatStateUsing(fn (mixed $state): string => $state instanceof WorkState ? self::STATES[$state->value] : '—')
                ->color(fn (mixed $state): string => match ($state) {
                    WorkState::Blocked, WorkState::FailedItem => 'danger',
                    WorkState::Deferred => 'warning',
                    WorkState::Succeeded => 'success',
                    default => 'gray',
                }),
            TextColumn::make('why')->label('Почему ждёт')->state(static fn (WorkItem $record): string => self::why($record))->wrap(),
            TextColumn::make('due_at')->label('Следующая попытка')->dateTime()->placeholder('—')->sortable(),
            TextColumn::make('execution_attempts')->label('Попыток')->description(static fn (WorkItem $record): string => sprintf('отказов по объекту %d из 3', $record->item_failures)),
            TextColumn::make('heartbeat_at')->label('Heartbeat')->dateTime()->placeholder('—')->toggleable(),
            TextColumn::make('cohort_key')->label('Когорта')->toggleable(),
            TextColumn::make('transition_seq')->label('Версия')->toggleable(isToggledHiddenByDefault: true),
        ])
            ->filters([
                SelectFilter::make('state')->label('Состояние')->options(self::STATES),
                SelectFilter::make('stage')->label('Стадия')->options(array_combine(
                    array_map(static fn (StageCode $stage): string => $stage->value, StageCode::cases()),
                    array_map(static fn (StageCode $stage): string => $stage->value, StageCode::cases()),
                )),
            ])
            ->recordActions([
                Action::make('cancel_work')
                    ->label('Отменить')
                    ->color('danger')
                    ->authorize(static fn (): bool => self::actor()->hasPermission(ProcessControlPermission::CancelWork))
                    ->visible(static fn (WorkItem $record): bool => ! in_array($record->state, [WorkState::Succeeded, WorkState::FailedItem, WorkState::Cancelled, WorkState::Superseded], true))
                    ->schema([Hidden::make('expected_version')->default(static fn (WorkItem $record): int => (int) $record->transition_seq)->required()->rules(['integer', 'min:0']), Textarea::make('reason')->label('Причина')->required()->maxLength(200)])
                    ->requiresConfirmation()
                    ->action(function (WorkItem $record, array $data): void {
                        self::report(app(ProcessOperatorCommands::class)->cancelWork(
                            self::actor(), new WorkId(new ProcessUuid($record->id)), (int) $data['expected_version'], self::reason($data),
                        ), 'Работа отменена');
                    }),
                Action::make('retry_handler_fault')
                    ->label('Повторить после исправления')
                    ->color('warning')
                    ->authorize(static fn (): bool => self::actor()->hasPermission(ProcessControlPermission::RetryHandlerFault))
                    ->visible(static fn (WorkItem $record): bool => $record->state === WorkState::Blocked && $record->reason_code === self::FAULT_LIMIT)
                    ->schema([Hidden::make('expected_version')->default(static fn (WorkItem $record): int => (int) $record->transition_seq)->required()->rules(['integer', 'min:0']), Textarea::make('reason')->label('Причина')->required()->maxLength(200)])
                    ->requiresConfirmation()
                    ->modalDescription('Создаётся новая работа со свежим бюджетом ошибок; прежняя заменяется, история сохраняется.')
                    ->action(function (WorkItem $record, array $data): void {
                        // Детерминированный commandId: повторное нажатие по той же версии дедуплицируется.
                        $commandId = new ProcessUuid(Uuid::uuid5(Uuid::NAMESPACE_URL, 'process-retry:'.$record->id.':'.$data['expected_version'])->toString());
                        self::report(app(ProcessOperatorCommands::class)->retryHandlerFaultedWork(
                            self::actor(), new WorkId(new ProcessUuid($record->id)), (int) $data['expected_version'], $commandId, self::reason($data),
                        ), 'Создана новая работа');
                    }),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    /** Человекочитаемая причина вместо токенов lease (observability#s04). */
    private static function why(WorkItem $record): string
    {
        $reason = $record->reason_code ?? '—';

        return match ($record->state) {
            WorkState::Deferred => 'Отложена: '.$reason,
            WorkState::Blocked => $reason === self::FAULT_LIMIT
                ? 'Заблокирована: исчерпан бюджет ошибок handler; общее «Возобновить» не снимает'
                : 'Заблокирована: '.$reason,
            WorkState::Running => 'Выполняется',
            WorkState::Admitted, WorkState::Ready => 'Ожидает исполнителя',
            WorkState::FailedItem, WorkState::Cancelled, WorkState::Superseded => 'Завершена: '.$reason,
            WorkState::Succeeded => 'Завершена',
        };
    }

    /** @param array<array-key, mixed> $data */
    private static function reason(array $data): string
    {
        $reason = $data['reason'] ?? '';

        return is_string($reason) ? $reason : '';
    }

    private static function report(OperatorCommandResult $result, string $success): void
    {
        $notification = Notification::make();
        if ($result->status->isSettled()) {
            $notification->title($success)->success();
        } else {
            $notification->title('Команда не применена: '.$result->status->value)->warning();
        }
        $notification->send();
    }

    private static function actor(): User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : throw new \LogicException('Process control requires an authenticated admin user.');
    }
}
