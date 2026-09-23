<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\OperationLogs\Tables;

use App\Enums\Procurement\OperationLogLevel;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class OperationLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('created_at')->label('Время')->dateTime()->sortable()->wrapHeader(),
            TextColumn::make('level')->label('Уровень')->badge(),
            TextColumn::make('stage')->label('Этап')->visibleFrom('md'),
            TextColumn::make('entity_type')->label('Тип объекта')->visibleFrom('md'),
            TextColumn::make('entity_id')->label('ИД объекта')->searchable(),
            TextColumn::make('message')->label('Сообщение')->wrapHeader()->wrap()->lineClamp(3)->tooltip(fn (?string $state): ?string => $state),
            TextColumn::make('context.error')->label('Причина')->wrap()->placeholder('—')
                ->copyable(),
            TextColumn::make('source')->label('Источник')->toggleable(isToggledHiddenByDefault: true),
        ])
            ->filters([SelectFilter::make('level')->options(collect(OperationLogLevel::cases())->mapWithKeys(static fn (OperationLogLevel $level): array => [$level->value => $level->value])->all()), SelectFilter::make('stage')->options(['listing' => 'Листинг', 'detail' => 'detail', 'document' => 'document', 'budget' => 'budget', 'proxy' => 'proxy']), SelectFilter::make('entity_type')->options(['crawler_run' => 'Прогон', 'procurement' => 'procurement', 'document' => 'document', 'proxy' => 'proxy'])])
            ->defaultSort('created_at', 'desc');
    }
}
