<?php

declare(strict_types=1);

namespace App\Filament\Resources\CrawlerRun\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Журнал прогона из sys_logs — read-only лента вех «упал тут / продолжил оттуда».
 */
final class LogsRelationManager extends RelationManager
{
    protected static string $relationship = 'logs';

    protected static ?string $title = 'Журнал прогона';

    public function table(Table $table): Table
    {
        return $table
            ->poll('30s')
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Время')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
                TextColumn::make('level')
                    ->label('Уровень')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'error' => 'danger',
                        'warning' => 'warning',
                        'debug' => 'gray',
                        default => 'info',
                    }),
                TextColumn::make('message')
                    ->label('Сообщение')
                    ->wrap(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([]);
    }
}
