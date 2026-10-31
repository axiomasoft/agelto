<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Procurements\Schemas;

use App\Dto\Procurement\History\ProcurementDurations;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\History\ProcurementTimelineBuilder;
use App\Services\Procurement\Platform\PlatformRegistry;
use App\Services\Procurement\Platform\PlatformUiResolver;
use Carbon\CarbonInterval;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;

class ProcurementInfolist
{
    /** @return list<Component> */
    private static function platformSections(Procurement $record): array
    {
        $code = $record->registry?->platform?->getAttribute('code');
        if (! is_string($code)) {
            return [];
        }
        $registry = app(PlatformRegistry::class);
        foreach ($registry->all() as $definition) {
            if ($definition->code->value === $code) {
                return app(PlatformUiResolver::class)
                    ->resolve($definition->code)?->procurementInfolistSections($record) ?? [];
            }
        }

        return [];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components(fn (Procurement $record): array => [
                ...self::platformSections($record),
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
                            ->state(fn (Procurement $record): string => $record->status->value),
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
                    ->label('Реестр'),
                TextEntry::make('source_identity_value')
                    ->label('Идентификатор источника')
                    ->columnSpanFull(),
                TextEntry::make('title')
                    ->label('Название')
                    ->columnSpanFull(),
                TextEntry::make('status')
                    ->label('Статус')
                    ->badge()
                    ->columnSpanFull(),
                TextEntry::make('source_stage')
                    ->label('Этап источника')
                    ->columnSpanFull(),
                TextEntry::make('procedure_type')
                    ->label('Тип процедуры')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('procedure_type_raw')
                    ->label('Исходный тип процедуры')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('customer_org_id')
                    ->label('Заказчик')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('initial_price')
                    ->label('Начальная цена')
                    ->money()
                    ->placeholder('-'),
                TextEntry::make('currency')
                    ->label('Валюта')
                    ->placeholder('-'),
                TextEntry::make('advance_percent')
                    ->label('Аванс, %')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('okved_code')
                    ->label('Код ОКВЭД')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('okved_additional')
                    ->label('Дополнительные ОКВЭД')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('region_code')
                    ->label('Код региона')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('published_at')
                    ->label('Опубликована')
                    ->dateTime(),
                TextEntry::make('source_updated_at')
                    ->label('Обновлена в источнике')
                    ->dateTime(),
                TextEntry::make('bidding_starts_at')
                    ->label('Начало подачи заявок')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('bidding_ends_at')
                    ->label('Окончание подачи заявок')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('summary_at')
                    ->label('Подведение итогов')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('detail_url')
                    ->label('Ссылка на карточку')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('detail_fetched_at')
                    ->label('Карточка получена')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('detail_work_state')->label('Состояние получения')->placeholder('Не запланировано'),
                TextEntry::make('detail_work_reason_code')->label('Причина ожидания или ошибки')->placeholder('-'),
                TextEntry::make('detail_work_due_at')->label('Следующая попытка')->dateTime()->placeholder('-'),
                TextEntry::make('current_revision_id')
                    ->label('Текущая редакция')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('current_revision_detected_at')
                    ->label('Текущая редакция обнаружена')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('revision_count')
                    ->label('Количество редакций')
                    ->numeric(),
                TextEntry::make('ignored_at')
                    ->label('Исключена')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('ignore_reason')
                    ->label('Причина исключения')
                    ->placeholder('-')
                    ->columnSpanFull(),
                TextEntry::make('first_seen_at')
                    ->label('Впервые обнаружена')
                    ->dateTime(),
                TextEntry::make('last_seen_at')
                    ->label('Последний раз обнаружена')
                    ->dateTime(),
                TextEntry::make('created_at')
                    ->label('Создана')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->label('Обновлена')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }

    private static function durations(Procurement $record): ProcurementDurations
    {
        /** @var array<int, ProcurementDurations> $durations */
        static $durations = [];

        $key = $record->getKey();

        assert(is_int($key));

        return $durations[$key] ??= app(ProcurementTimelineBuilder::class)
            ->build(procurement: $record, from: Carbon::parse(self::rawDateTime($record, 'first_seen_at')), until: now())
            ->durations;
    }

    private static function rawDateTime(Procurement $record, string $attribute): \DateTimeInterface|float|int|string|null
    {
        $value = $record->getRawOriginal($attribute);

        return is_string($value) || is_int($value) || is_float($value) || $value instanceof \DateTimeInterface
            ? $value
            : null;
    }

    private static function durationLabel(?int $seconds): string
    {
        return $seconds === null ? 'идёт' : CarbonInterval::seconds($seconds)->cascade()->forHumans();
    }
}
