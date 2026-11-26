<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Process\Runs\Tables;

use App\Dto\Procurement\Process\OperatorCommandResult;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\RunFacts;
use App\Dto\Procurement\Process\WorkStateCounts;
use App\Enums\Procurement\Process\AccountingRole;
use App\Enums\Procurement\Process\DiscoveryOutcome;
use App\Enums\Procurement\Process\ProcessRunState;
use App\Enums\Procurement\Process\WorkState;
use App\Filament\Resources\Procurement\Process\Support\OperatorActionInput;
use App\Guards\Admin\Permissions\ProcessControlPermission;
use App\Models\Procurement\Process\ProcessRun;
use App\Models\User;
use App\Services\Procurement\Process\ProcessOperatorCommands;
use App\Services\Procurement\Process\RunStateReducer;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class ProcessRunsTable
{
    private const array DISCOVERY = ['collecting' => 'Идёт сбор', 'complete' => 'Полный обход', 'incomplete' => 'Обход неполный'];

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(self::withWorkCounts(...))
            ->columns([
                TextColumn::make('requested_at')->label('Запрошен')->dateTime()->sortable(),
                TextColumn::make('kind')->label('Вид')->badge()->formatStateUsing(fn (mixed $state): string => $state instanceof \BackedEnum ? (string) $state->value : '—'),
                TextColumn::make('status_text')->label('Состояние')->badge()
                    ->state(static fn (ProcessRun $record): string => self::status($record))
                    ->color(static fn (ProcessRun $record): string => match (self::runState($record)) {
                        ProcessRunState::Completed => 'success', ProcessRunState::CompletedWithIssues, ProcessRunState::Waiting => 'warning', default => 'gray'
                    }),
                TextColumn::make('discovery_outcome')->label('Обнаружение')
                    ->formatStateUsing(fn (mixed $state): string => is_string($state) ? (self::DISCOVERY[$state] ?? $state) : '—')
                    ->description(static fn (ProcessRun $record): ?string => $record->discovery_reason_code),
                TextColumn::make('required_total')->label('Required work')
                    ->description(static function (ProcessRun $record): string {
                        $counts = self::requiredCounts($record);

                        return sprintf('в работе %d, ждут %d', $counts->active(), $counts->waiting());
                    }),
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
                    ->visible(static fn (ProcessRun $record): bool => $record->cancel_requested_at === null
                        && ! self::runState($record)->terminal())
                    ->schema([OperatorActionInput::versionField('cancel_run'), Textarea::make('reason')->label('Причина')->required()->maxLength(OperatorActionInput::REASON_LIMIT)])
                    ->requiresConfirmation()
                    ->modalDescription('Отменяется намерение запуска. Общая работа, нужная другим запускам, не отменяется.')
                    ->action(function (Action $action, ProcessRun $record, array $data): void {
                        $version = OperatorActionInput::confirmedVersion($action, 'cancel_run', $record->id, $data);
                        self::report(OperatorActionInput::attempt($action, fn (): OperatorCommandResult => app(ProcessOperatorCommands::class)->cancelRun(
                            self::actor(), new ProcessUuid($record->id), $version, OperatorActionInput::reason($data),
                        )), 'Запуск отменён');
                    }),
            ])
            ->defaultSort('requested_at', 'desc');
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private static function withWorkCounts(Builder $query): Builder
    {
        $required = AccountingRole::Required->value;
        $counts = DB::table(table: 'prc_process_run_work', as: 'links')
            ->join(table: 'prc_process_work as work', first: 'work.id', operator: '=', second: 'links.work_id')
            ->whereColumn(first: 'links.run_id', operator: '=', second: 'prc_process_runs.id')
            ->selectRaw('count(*) as linked_total')
            ->selectRaw(expression: 'count(*) filter (where links.accounting_role = ?) as required_total', bindings: [$required]);

        foreach (WorkState::cases() as $state) {
            $counts->selectRaw(
                expression: "count(*) filter (where links.accounting_role = ? and work.state = ?) as required_{$state->value}",
                bindings: [$required, $state->value],
            );
        }

        return $query->addSelect(['prc_process_runs.*', 'work_counts.*'])
            ->leftJoinLateral(query: $counts, as: 'work_counts')
            ->withCasts(['required_total' => 'integer']);
    }

    private static function requiredCounts(ProcessRun $record): WorkStateCounts
    {
        $counts = [];
        foreach (WorkState::cases() as $state) {
            $counts[$state->value] = self::count(record: $record, attribute: "required_{$state->value}");
        }

        return WorkStateCounts::fromMap($counts);
    }

    private static function count(ProcessRun $record, string $attribute): int
    {
        $value = $record->getAttribute($attribute);

        return is_numeric($value) ? (int) $value : 0;
    }

    private static function runState(ProcessRun $record): ProcessRunState
    {
        return (new RunStateReducer)->reduce(new RunFacts(
            cancelRequested: $record->cancel_requested_at !== null,
            discovery: DiscoveryOutcome::from($record->discovery_outcome),
            activated: $record->started_at !== null || self::count(record: $record, attribute: 'linked_total') > 0,
            required: self::requiredCounts($record),
        ));
    }

    private static function status(ProcessRun $record): string
    {
        return match (self::runState($record)) {
            ProcessRunState::Requested => 'Запрошен',
            ProcessRunState::Running => 'Выполняется',
            ProcessRunState::Waiting => 'Ждёт',
            ProcessRunState::Completed => 'Завершён',
            ProcessRunState::CompletedWithIssues => 'Завершён с проблемами',
            ProcessRunState::Cancelled => 'Отменён оператором',
        };
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
