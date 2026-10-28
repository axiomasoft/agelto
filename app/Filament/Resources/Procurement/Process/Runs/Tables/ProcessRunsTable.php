<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Process\Runs\Tables;

use App\Dto\Procurement\Process\OperatorCommandResult;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Process\AccountingRole;
use App\Enums\Procurement\Process\DiscoveryOutcome;
use App\Enums\Procurement\Process\WorkState;
use App\Guards\Admin\Permissions\ProcessControlPermission;
use App\Models\Procurement\Process\ProcessRun;
use App\Models\User;
use App\Services\Procurement\Process\ProcessOperatorCommands;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Database\Eloquent\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;

final class ProcessRunsTable
{
    private const array DISCOVERY = ['collecting' => 'Идёт сбор', 'complete' => 'Полный обход', 'incomplete' => 'Обход неполный'];

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(static fn (Builder $query): Builder => $query
                ->withCount([
                    'workLinks as required_total' => static fn (BuilderContract $links) => $links->where('accounting_role', AccountingRole::Required->value),
                    'workLinks as required_active' => static fn (BuilderContract $links) => $links->where('accounting_role', AccountingRole::Required->value)
                        ->whereIn('work_id', self::workIds([WorkState::Ready, WorkState::Admitted, WorkState::Running])),
                    'workLinks as required_waiting' => static fn (BuilderContract $links) => $links->where('accounting_role', AccountingRole::Required->value)
                        ->whereIn('work_id', self::workIds([WorkState::Deferred, WorkState::Blocked])),
                ]))
            ->columns([
                TextColumn::make('requested_at')->label('Запрошен')->dateTime()->sortable(),
                TextColumn::make('kind')->label('Вид')->badge()->formatStateUsing(fn (mixed $state): string => $state instanceof \BackedEnum ? (string) $state->value : '—'),
                TextColumn::make('status_text')->label('Состояние')->badge()
                    ->state(static fn (ProcessRun $record): string => self::status($record))
                    ->color(static fn (ProcessRun $record): string => $record->cancel_requested_at !== null ? 'gray' : (self::count($record, 'required_waiting') > 0 ? 'warning' : 'success')),
                TextColumn::make('discovery_outcome')->label('Обнаружение')
                    ->formatStateUsing(fn (mixed $state): string => is_string($state) ? (self::DISCOVERY[$state] ?? $state) : '—')
                    ->description(static fn (ProcessRun $record): ?string => $record->discovery_reason_code),
                TextColumn::make('required_total')->label('Required work')->description(static fn (ProcessRun $record): string => sprintf('в работе %d, ждут %d', self::count($record, 'required_active'), self::count($record, 'required_waiting'))),
                TextColumn::make('cancel_requested_at')->label('Отмена запрошена')->dateTime()->placeholder('—')->toggleable(),
                TextColumn::make('requested_by')->label('Кто (user id)')->placeholder('—')->toggleable(),
                TextColumn::make('transition_seq')->label('Версия')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('discovery_outcome')->label('Обнаружение')->options(self::DISCOVERY),
            ])
            ->recordActions([
                Action::make('cancel_run')
                    ->label('Отменить запуск')
                    ->color('danger')
                    ->authorize(static fn (): bool => self::actor()->hasPermission(ProcessControlPermission::CancelRun))
                    ->visible(static fn (ProcessRun $record): bool => $record->cancel_requested_at === null)
                    ->schema([Textarea::make('reason')->label('Причина')->required()->maxLength(500)])
                    ->requiresConfirmation()
                    ->modalDescription('Отменяется намерение запуска. Общая работа, нужная другим запускам, не отменяется.')
                    ->action(function (ProcessRun $record, array $data): void {
                        $reason = $data['reason'] ?? '';
                        self::report(app(ProcessOperatorCommands::class)->cancelRun(
                            self::actor(), new ProcessUuid($record->id), (int) $record->transition_seq, is_string($reason) ? $reason : '',
                        ), 'Запуск отменён');
                    }),
            ])
            ->defaultSort('requested_at', 'desc');
    }

    /**
     * @param  list<WorkState>  $states
     */
    private static function workIds(array $states): \Closure
    {
        return static fn (QueryBuilder $query) => $query->select('id')->from('prc_process_work')
            ->whereIn('state', array_map(static fn (WorkState $state): string => $state->value, $states));
    }

    private static function count(ProcessRun $record, string $attribute): int
    {
        $value = $record->getAttribute($attribute);

        return is_numeric($value) ? (int) $value : 0;
    }

    private static function status(ProcessRun $record): string
    {
        if ($record->cancel_requested_at !== null) {
            return 'Отменён оператором';
        }
        if (self::count($record, 'required_active') > 0) {
            return 'Выполняется';
        }
        if (self::count($record, 'required_waiting') > 0) {
            return 'Ждёт';
        }

        return $record->discovery_outcome === DiscoveryOutcome::Collecting->value ? 'Сбор не закрыт' : 'Завершён';
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
