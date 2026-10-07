<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Crawler\SourceCircuits\Tables;

use App\Enums\Procurement\Crawler\SourceStage;
use App\Models\Procurement\Crawler\SourceCircuit;
use App\Services\Procurement\SourceControl\SourceStageOperations;
use App\Services\Procurement\SourceControl\SuspendedRunResumer;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Пульт источников. «Пауза» и «Возобновить сейчас» — `SourceStageOperations`: состояние меняет
 * тот же `SourceCircuitBreaker`, что и автоматика, событие пишет `SourceEventReporter`.
 */
final class SourceCircuitsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('platform.name')->label('Площадка')->searchable(),
            TextColumn::make('stage')->label('Этап')->badge(),
            TextColumn::make('status')->label('Состояние')->badge()
                ->state(fn (SourceCircuit $record): string => $record->statusLabel())
                ->color(fn (SourceCircuit $record): string => $record->admits() ? 'success' : 'danger'),
            IconColumn::make('enabled')->label('Автопауза')->boolean(),
            TextColumn::make('consecutive_failures')->label('Отказов подряд'),
            TextColumn::make('failure_threshold')->label('Порог'),
            TextColumn::make('retry_at')->label('Автоповтор в')->dateTime()->placeholder('—'),
            TextColumn::make('last_failure_kind')->label('Последний отказ')->badge()->placeholder('—'),
            TextColumn::make('last_failure_at')->label('Когда')->dateTime()->since()->placeholder('—'),
            TextColumn::make('changed_by')->label('Изменил')->toggleable(isToggledHiddenByDefault: true),
        ])
            ->recordActions([
                EditAction::make()->label('Пороги'),
                Action::make('pause')
                    ->label('Пауза')
                    ->color('danger')
                    ->authorize('update')
                    ->visible(fn (SourceCircuit $record): bool => ! $record->manually_paused)
                    ->schema([Textarea::make('reason')->label('Причина')->maxLength(500)])
                    ->requiresConfirmation()
                    ->action(function (SourceCircuit $record, array $data): void {
                        $reason = $data['reason'] ?? null;
                        app(SourceStageOperations::class)->pause(
                            circuit: $record,
                            actor: self::actor(),
                            reason: is_string($reason) ? $reason : null,
                        );
                        Notification::make()->title('Этап поставлен на паузу')->warning()->send();
                    }),
                Action::make('resumeNow')
                    ->label('Возобновить сейчас')
                    ->color('success')
                    ->authorize('update')
                    ->visible(fn (SourceCircuit $record): bool => ! $record->admits())
                    ->requiresConfirmation()
                    ->modalDescription('Пауза и ожидание снимаются, счётчик сбрасывается; приостановленные прогоны листинга ставятся в очередь сразу.')
                    ->action(function (SourceCircuit $record): void {
                        $actor = self::actor();
                        app(SourceStageOperations::class)->resume(circuit: $record, actor: $actor);
                        $dispatched = $record->stage === SourceStage::Listing
                            ? app(SuspendedRunResumer::class)->resumePlatform(platformId: $record->platform_id, actor: $actor)
                            : 0;
                        Notification::make()->title("Возобновлено; прогонов в очереди: {$dispatched}")->success()->send();
                    }),
            ])
            ->defaultSort('platform_id');
    }

    private static function actor(): string
    {
        $user = Auth::user();
        $email = $user?->getAttribute('email');

        return is_string($email) && $email !== '' ? $email : 'operator';
    }
}
