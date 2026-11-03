<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\OperationLogs\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Read-only canonical transition journal; no second logging writer. */
final class OperationLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('occurred_at')->label('Время')->dateTime()->sortable(),
            TextColumn::make('entity_kind')->label('Тип объекта')->visibleFrom('md'),
            TextColumn::make('new_state')->label('Состояние')->badge(),
            TextColumn::make('reason_code')->label('Причина')->placeholder('—')->wrap(),
            TextColumn::make('details.reason')->label('Комментарий')->placeholder('—')->wrap(),
            TextColumn::make('actor_kind')->label('Автор')->visibleFrom('md'),
            TextColumn::make('work_id')->label('Работа')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('run_id')->label('Запуск')->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('event_id')->label('Событие')->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('entity_kind')->label('Тип объекта')->options([
                'work' => 'Работа', 'run' => 'Запуск', 'control' => 'Управление', 'incident' => 'Инцидент',
            ]),
            SelectFilter::make('new_state')->label('Состояние')->options([
                'ready' => 'Готова', 'running' => 'Исполняется', 'deferred' => 'Ожидает',
                'blocked' => 'Заблокирована', 'succeeded' => 'Завершена', 'failed_item' => 'Ошибка входа',
                'cancelled' => 'Отменена', 'superseded' => 'Заменена', 'paused' => 'Пауза', 'enabled' => 'Включено',
            ]),
        ])->defaultSort('occurred_at', 'desc');
    }
}
