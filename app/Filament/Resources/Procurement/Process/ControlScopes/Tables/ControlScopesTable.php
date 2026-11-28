<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Process\ControlScopes\Tables;

use App\Dto\Procurement\Process\Governance\OperatorCommandResult;
use App\Enums\Procurement\Process\Governance\ControlMode;
use App\Filament\Resources\Procurement\Process\Support\OperatorActionInput;
use App\Guards\Admin\Permissions\ProcessControlPermission;
use App\Models\Procurement\Process\ControlScope;
use App\Models\User;
use App\Services\Procurement\Process\Diagnostics\ProcessOperatorCommands;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

final class ControlScopesTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('scope_key')->label('Область')->searchable()->sortable(),
            TextColumn::make('scope_kind')->label('Тип')->formatStateUsing(fn (mixed $state): string => $state instanceof \BackedEnum ? (string) $state->value : '—'),
            TextColumn::make('desired_mode')->label('Режим')->badge()
                ->formatStateUsing(fn (mixed $state): string => $state === ControlMode::Paused ? 'Пауза оператора' : 'Работает')
                ->color(fn (mixed $state): string => $state === ControlMode::Paused ? 'warning' : 'success'),
            TextColumn::make('control_version')->label('Версия')->sortable(),
            TextColumn::make('change_reason')->label('Причина')->placeholder('—')->wrap()->lineClamp(3)->tooltip(fn (?string $state): ?string => $state),
            TextColumn::make('changed_by')->label('Кто (user id)')->placeholder('—')->toggleable(),
            TextColumn::make('changed_at')->label('Когда')->dateTime()->placeholder('—')->sortable(),
        ])
            ->filters([
                SelectFilter::make('desired_mode')->label('Режим')->options([
                    ControlMode::Paused->value => 'Пауза оператора',
                    ControlMode::Enabled->value => 'Работает',
                ]),
            ])
            ->recordActions([
                self::command('pause', 'Пауза', 'danger', ProcessControlPermission::PauseScope, ControlMode::Enabled)
                    ->action(function (Action $action, ControlScope $record, array $data): void {
                        $version = OperatorActionInput::confirmedVersion($action, 'pause', $record->id, $data);
                        self::report(OperatorActionInput::attempt($action, fn (): OperatorCommandResult => app(ProcessOperatorCommands::class)->pauseScope(
                            self::actor(), $record->scope_key, $version, OperatorActionInput::reason($data),
                        )), 'Область поставлена на паузу');
                    }),
                self::command('resume', 'Возобновить', 'success', ProcessControlPermission::ResumeScope, ControlMode::Paused)
                    ->action(function (Action $action, ControlScope $record, array $data): void {
                        $version = OperatorActionInput::confirmedVersion($action, 'resume', $record->id, $data);
                        self::report(OperatorActionInput::attempt($action, fn (): OperatorCommandResult => app(ProcessOperatorCommands::class)->resumeScope(
                            self::actor(), $record->scope_key, $version, OperatorActionInput::reason($data),
                        )), 'Пауза области снята');
                    }),
            ])
            ->defaultSort('scope_key');
    }

    /** Общая часть Pause/Resume: право, видимость по режиму и обязательная причина. */
    private static function command(string $name, string $label, string $color, ProcessControlPermission $permission, ControlMode $visibleWhen): Action
    {
        return Action::make($name)
            ->label($label)
            ->color($color)
            ->authorize(static fn (): bool => self::actor()->hasPermission($permission))
            ->visible(static fn (ControlScope $record): bool => $record->desired_mode === $visibleWhen)
            ->schema([
                // Scope имеет control_version; общий versionField читает transition_seq work/run.
                Hidden::make(OperatorActionInput::FIELD)->required()->default(
                    static fn (ControlScope $record): string => OperatorActionInput::issue($name, $record->id, (int) $record->control_version),
                ),
                Textarea::make('reason')->label('Причина')->required()->maxLength(OperatorActionInput::REASON_LIMIT),
            ])
            ->requiresConfirmation();
    }

    /** Конфликт версии не ошибка UI: оператор видел устаревшую строку и перечитает таблицу. */
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
