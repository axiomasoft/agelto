<?php

namespace App\Filament\Resources\Procurement\Procurements\Schemas;

use App\Dto\Procurement\History\ProcurementDurations;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\History\ProcurementTimelineBuilder;
use Carbon\CarbonInterval;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class ProcurementInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Наблюдаемая длительность')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('history.discovered_at')
                            ->label('Обнаружена')
                            ->state(fn (Procurement $record): ?string => self::durations($record)->discoveredAt?->format('d.m.Y H:i'))
                            ->placeholder('—'),
                        TextEntry::make('history.published_at')
                            ->label('Опубликована')
                            ->state(fn (Procurement $record): string => self::durations($record)->publishedAt->format('d.m.Y H:i')),
                        TextEntry::make('history.current_status')
                            ->label('Текущий статус')
                            ->state(fn (Procurement $record): string => (string) $record->getRawOriginal('status')),
                        TextEntry::make('history.terminal_status')
                            ->label('Терминальный статус')
                            ->state(fn (Procurement $record): ?string => self::durations($record)->terminalStatus?->value)
                            ->placeholder('идёт'),
                        TextEntry::make('history.total_duration')
                            ->label('Наблюдаемая длительность')
                            ->state(fn (Procurement $record): string => self::durationLabel(self::durations($record)->totalDuration)),
                        TextEntry::make('history.by_status')
                            ->label('Время в статусах')
                            ->state(fn (Procurement $record): array => collect(self::durations($record)->byStatus)
                                ->map(fn (int $seconds, string $status): string => "{$status}: ".self::durationLabel($seconds))
                                ->values()
                                ->all())
                            ->listWithLineBreaks()
                            ->placeholder('—'),
                    ]),
                TextEntry::make('uuid')
                    ->label('UUID'),
                TextEntry::make('registry.name')
                    ->label('Registry'),
                TextEntry::make('registry_number')
                    ->columnSpanFull(),
                TextEntry::make('etp_number')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('ikz')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('title')
                    ->columnSpanFull(),
                TextEntry::make('status')
                    ->badge()
                    ->columnSpanFull(),
                TextEntry::make('source_stage')
                    ->columnSpanFull(),
                TextEntry::make('procedure_type')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('procedure_type_raw')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('customer_org_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('initial_price')
                    ->money()
                    ->placeholder('-'),
                TextEntry::make('currency')
                    ->placeholder('-'),
                TextEntry::make('advance_percent')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('okved_code')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('okved_additional')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('region_code')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('published_at')
                    ->dateTime(),
                TextEntry::make('source_updated_at')
                    ->dateTime(),
                TextEntry::make('bidding_starts_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('bidding_ends_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('summary_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('detail_url')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('detail_fetched_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('detail_attempts')
                    ->numeric(),
                TextEntry::make('detail_last_error')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('detail_next_retry_at')
                    ->dateTime()
                    ->placeholder('-'),
                IconEntry::make('detail_failed')
                    ->boolean(),
                TextEntry::make('registry_guid')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('current_revision_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('current_revision_detected_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('revision_count')
                    ->numeric(),
                TextEntry::make('ignored_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('ignore_reason')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('first_seen_at')
                    ->dateTime(),
                TextEntry::make('last_seen_at')
                    ->dateTime(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }

    private static function durations(Procurement $record): ProcurementDurations
    {
        /** @var array<int, ProcurementDurations> $durations */
        static $durations = [];

        return $durations[$record->getKey()] ??= app(ProcurementTimelineBuilder::class)
            ->build(procurement: $record, from: Carbon::parse($record->getRawOriginal('first_seen_at')), until: now())
            ->durations;
    }

    private static function durationLabel(?int $seconds): string
    {
        return $seconds === null ? 'идёт' : CarbonInterval::seconds($seconds)->cascade()->forHumans();
    }
}
