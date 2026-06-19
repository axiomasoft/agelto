<?php

declare(strict_types=1);

namespace App\Filament\Resources\CrawlerRun\Tables;

use App\Enums\Crawler\CrawlerRunStatus;
use App\Models\Crawler\CrawlerRun;
use App\Process\Crawling\Run\CrawlerRunManager;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class CrawlerRunsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->poll('30s')
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('crawler.platform.name')
                    ->label('Площадка')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge(),
                TextColumn::make('last_heartbeat_at')
                    ->label('Heartbeat')
                    ->since()
                    ->placeholder('—')
                    ->color(fn (CrawlerRun $record): ?string => self::isHeartbeatStale($record) ? 'danger' : null)
                    ->sortable(),
                TextColumn::make('cursor')
                    ->label('Курсор')
                    ->state(fn (CrawlerRun $record): string => self::cursorLabel($record)),
                TextColumn::make('attempts')
                    ->label('Попыток')
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('last_error')
                    ->label('Последняя ошибка')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('started_at')
                    ->label('Старт')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('finished_at')
                    ->label('Финиш')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(CrawlerRunStatus::class),
                SelectFilter::make('crawler_id')
                    ->label('Площадка')
                    ->relationship('crawler', 'id', fn ($query) => $query->with('platform'))
                    ->getOptionLabelFromRecordUsing(fn ($record): string => $record->platform?->name ?? "Краулер #{$record->id}"),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    private static function isHeartbeatStale(CrawlerRun $record): bool
    {
        return $record->status === CrawlerRunStatus::Running
            && $record->last_heartbeat_at !== null
            && $record->last_heartbeat_at->lt(now()->subMinutes(CrawlerRunManager::HEARTBEAT_STALE_MINUTES));
    }

    private static function cursorLabel(CrawlerRun $record): string
    {
        $parts = [];

        if ($record->day_from !== null) {
            $parts[] = 'день '.$record->day_from->toDateString();
        }

        if ($record->current_page !== null) {
            $parts[] = 'стр. '.$record->current_page;
        }

        if ($record->price_from !== null || $record->price_to !== null) {
            $parts[] = 'цена '.($record->price_from ?? '0').'–'.($record->price_to ?? '∞');
        }

        return $parts === [] ? '—' : implode(', ', $parts);
    }
}
