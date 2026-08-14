<?php

namespace App\Filament\Resources\Procurement\Crawler\Runs\Schemas;

use App\Models\Procurement\Crawler\Run;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Карточка прогона (P3.6): «Где остановились» декодирует курсор (день/блок/ценовой стек/
 * `reachedKnown`) в читаемый вид, «Итоги» рендерит ключи `stats` ЯВНЫМ перечнем с русскими
 * подписями (Implementation Rule 2 — дамп jsonb перестаёт быть читаемым ровно тогда, когда
 * нужен). Отсутствующий ключ показывается как «—», а не «0»/пустая строка (Implementation
 * Rule 3): «не измерено» и «ноль» — разные факты.
 */
class RunInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Прогон')
                    ->schema([
                        TextEntry::make('crawler.name')
                            ->label('Профиль'),
                        TextEntry::make('status')
                            ->label('Статус')
                            ->badge(),
                        TextEntry::make('purpose')
                            ->label('Назначение')
                            ->badge(),
                        TextEntry::make('subject_key')
                            ->label('Субъект')
                            ->placeholder('—'),
                        TextEntry::make('started_at')
                            ->label('Начат')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('finished_at')
                            ->label('Завершён')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('last_heartbeat_at')
                            ->label('Последний heartbeat')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('queue_job_id')
                            ->label('Job в очереди')
                            ->placeholder('—'),
                        TextEntry::make('attempts')
                            ->label('Попытки')
                            ->numeric(),
                        IconEntry::make('failed_permanently')
                            ->label('Провален окончательно')
                            ->boolean(),
                        TextEntry::make('last_error')
                            ->label('Последняя ошибка')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('last_error_at')
                            ->label('Когда упал')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('next_retry_at')
                            ->label('Следующий ретрай')
                            ->dateTime()
                            ->placeholder('—'),
                    ])
                    ->columns(3),

                Section::make('Остановка оператором')
                    ->schema([
                        TextEntry::make('stop_kind')
                            ->label('Запрошенный вид')
                            ->badge()
                            ->placeholder('—'),
                        TextEntry::make('stopRequestedBy.name')
                            ->label('Кто запросил')
                            ->placeholder('—'),
                        TextEntry::make('stop_requested_at')
                            ->label('Когда запросил')
                            ->dateTime()
                            ->placeholder('—'),
                    ])
                    ->columns(3)
                    ->visible(static fn (Run $record): bool => $record->stop_kind !== null || $record->stop_requested_at !== null),

                Section::make('Где остановились')
                    ->description('Курсор прогона — иерархический (день/блок/ценовой стек для archive, только блок для fresh).')
                    ->schema([
                        TextEntry::make('cursor_day')
                            ->label('День')
                            ->state(static fn (Run $record): string => self::cursorValue($record, 'day')),
                        TextEntry::make('cursor_block_from')
                            ->label('Блок')
                            ->state(static fn (Run $record): string => self::cursorValue($record, 'blockFrom')),
                        TextEntry::make('cursor_price_stack')
                            ->label('Ценовой стек')
                            ->state(static fn (Run $record): string => self::cursorPriceStack($record)),
                        TextEntry::make('cursor_reached_known')
                            ->label('Дошли до известного')
                            ->state(static fn (Run $record): string => self::cursorValue($record, 'reachedKnown')),
                    ])
                    ->columns(4),

                Section::make('Итоги')
                    ->schema([
                        TextEntry::make('stats_stop_reason')
                            ->label('Причина остановки')
                            ->state(static fn (Run $record): string => self::statValue($record, 'stop_reason')),
                        TextEntry::make('stats_stop_threshold_at')
                            ->label('Порог остановки (время)')
                            ->state(static fn (Run $record): string => self::statValue($record, 'stop_threshold_at')),
                        TextEntry::make('stats_cap_hit')
                            ->label('Возможен пропуск (cap)')
                            ->state(static fn (Run $record): string => self::statValue($record, 'cap_hit')),
                        TextEntry::make('stats_possible_skip_window')
                            ->label('Окно возможного пропуска')
                            ->state(static fn (Run $record): string => self::statPossibleSkipWindow($record)),
                        TextEntry::make('stats_scan_gap_seconds')
                            ->label('Отставание от предыдущего скана, сек')
                            ->state(static fn (Run $record): string => self::statValue($record, 'scan_gap_seconds')),
                        TextEntry::make('stats_oldest_update_seen')
                            ->label('Самая старая дата обновления среди снятых блоков')
                            ->state(static fn (Run $record): string => self::statValue($record, 'oldest_update_seen')),
                        TextEntry::make('stats_checkpoints')
                            ->label('Чекпоинтов (self-dispatch)')
                            ->state(static fn (Run $record): string => self::statValue($record, 'checkpoints')),
                        TextEntry::make('stats_backlog_rescanned_at')
                            ->label('Пересканирован backlog\'ом')
                            ->state(static fn (Run $record): string => self::statValue($record, 'backlog_rescanned_at')),
                    ])
                    ->columns(2),
            ]);
    }

    private static function cursorValue(Run $record, string $key): string
    {
        $value = $record->cursor?->get($key);

        if ($value === null) {
            return '—';
        }

        return match (true) {
            is_bool($value) => $value ? 'да' : 'нет',
            is_int($value), is_float($value), is_string($value) => (string) $value,
            default => '—',
        };
    }

    private static function cursorPriceStack(Run $record): string
    {
        $stack = $record->cursor?->get('priceStack');

        if (! is_array($stack) || $stack === []) {
            return '—';
        }

        return implode(' → ', array_values(array_filter($stack, is_string(...))));
    }

    private static function statValue(Run $record, string $key): string
    {
        $value = $record->stats?->get($key);

        if ($value === null) {
            return '—';
        }

        return match (true) {
            is_bool($value) => $value ? 'да' : 'нет',
            is_int($value), is_float($value), is_string($value) => (string) $value,
            default => '—',
        };
    }

    private static function statPossibleSkipWindow(Run $record): string
    {
        $window = $record->stats?->get('possible_skip_window');

        if (! is_array($window)) {
            return '—';
        }

        $from = $window['from'] ?? null;
        $to = $window['to'] ?? null;

        $from = is_string($from) ? $from : '—';
        $to = is_string($to) ? $to : '—';

        return "{$from} — {$to}";
    }
}
