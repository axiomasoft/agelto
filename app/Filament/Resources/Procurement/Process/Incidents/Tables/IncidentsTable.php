<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Process\Incidents\Tables;

use App\Dto\Procurement\Process\Governance\OperatorCommandResult;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Filament\Resources\Procurement\Process\Support\OperatorActionInput;
use App\Guards\Admin\Permissions\ProcessControlPermission;
use App\Models\Procurement\Process\Incident;
use App\Models\User;
use App\Services\Procurement\Process\Diagnostics\ProcessOperatorCommands;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

final class IncidentsTable
{
    private const array STATES = ['open' => 'Открыт', 'acknowledged' => 'Принят', 'resolved' => 'Решён'];

    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('last_seen_at')->label('Последний раз')->dateTime()->sortable(),
            TextColumn::make('kind')->label('Тип')->badge()->searchable(),
            TextColumn::make('scope_key')->label('Область')->searchable(),
            TextColumn::make('state')->label('Состояние')->badge()
                ->formatStateUsing(fn (mixed $state): string => is_string($state) ? (self::STATES[$state] ?? $state) : '—')
                ->color(fn (mixed $state): string => match ($state) {
                    'open' => 'danger',
                    'acknowledged' => 'warning',
                    default => 'success',
                }),
            TextColumn::make('occurrences')->label('Повторов')->sortable(),
            TextColumn::make('first_seen_at')->label('Впервые')->dateTime()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('acknowledged_by')->label('Принял (user id)')->placeholder('—')->toggleable(),
            TextColumn::make('resolved_at')->label('Решён')->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
        ])
            ->filters([
                SelectFilter::make('state')->label('Состояние')->options(self::STATES),
            ])
            ->recordActions([
                Action::make('acknowledge')
                    ->label('Принять')
                    ->authorize(static fn (): bool => self::actor()->hasPermission(ProcessControlPermission::AcknowledgeIncident))
                    ->visible(static fn (Incident $record): bool => $record->state === 'open')
                    ->schema([
                        OperatorActionInput::versionField('acknowledge'),
                        Textarea::make('reason')->label('Причина')->required()->maxLength(OperatorActionInput::REASON_LIMIT),
                    ])
                    ->action(function (Action $action, Incident $record, array $data): void {
                        $id = $record->getKey();
                        $recordId = is_string($id) ? $id : '';
                        $version = OperatorActionInput::confirmedVersion($action, 'acknowledge', $recordId, $data);
                        $result = OperatorActionInput::attempt($action, fn (): OperatorCommandResult => app(ProcessOperatorCommands::class)->acknowledgeIncident(
                            self::actor(), new ProcessUuid($recordId), $version, OperatorActionInput::reason($data),
                        ));
                        $notification = Notification::make();
                        if ($result->status->isSettled()) {
                            $notification->title('Инцидент принят')->success();
                        } else {
                            $notification->title('Команда не применена: '.$result->status->value)->warning();
                        }
                        $notification->send();
                    }),
            ])
            ->defaultSort('last_seen_at', 'desc');
    }

    private static function actor(): User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : throw new \LogicException('Process control requires an authenticated admin user.');
    }
}
