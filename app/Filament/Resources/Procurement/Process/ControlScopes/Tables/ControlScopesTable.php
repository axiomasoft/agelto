<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Process\ControlScopes\Tables;

use App\Dto\Procurement\Process\OperatorCommandResult;
use App\Enums\Procurement\Process\ControlMode;
use App\Guards\Admin\Permissions\ProcessControlPermission;
use App\Models\Procurement\Process\ControlScope;
use App\Models\User;
use App\Services\Procurement\Process\ProcessOperatorCommands;
use Filament\Actions\Action;
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
                    ->action(fn (ControlScope $record, array $data) => self::report(app(ProcessOperatorCommands::class)->pauseScope(self::actor(), $record->scope_key, (int) $record->control_version, self::reason($data)), 'Область поставлена на паузу')),
                self::command('resume', 'Возобновить', 'success', ProcessControlPermission::ResumeScope, ControlMode::Paused)
                    ->action(fn (ControlScope $record, array $data) => self::report(app(ProcessOperatorCommands::class)->resumeScope(self::actor(), $record->scope_key, (int) $record->control_version, self::reason($data)), 'Пауза области снята')),
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
            ->schema([Textarea::make('reason')->label('Причина')->required()->maxLength(500)])
            ->requiresConfirmation();
    }

    /** @param array<array-key, mixed> $data */
    private static function reason(array $data): string
    {
        $reason = $data['reason'] ?? '';

        return is_string($reason) ? $reason : '';
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
