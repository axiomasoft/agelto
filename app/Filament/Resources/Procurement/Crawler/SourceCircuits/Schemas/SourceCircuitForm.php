<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Crawler\SourceCircuits\Schemas;

use App\Dto\Procurement\Crawler\SourceControlPolicy;
use App\Dto\Procurement\Crawler\StagePolicy;
use App\Models\Procurement\Crawler\SourceCircuit;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class SourceCircuitForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Брейкер этапа')
                ->description('Пустое поле — значение из config/crawler.php (source_control); заполненное перекрывает его для этой площадки. Какие отказы считаются, задаёт конфиг.')
                ->schema([
                    Toggle::make('enabled')
                        ->label('Автоматическая пауза при массовых отказах')
                        ->helperText('Выключено: отказы только записываются инцидентами, этап не останавливается. Ручная пауза действует всегда.'),
                    self::override('failure_threshold', 'Порог отказов подряд → ожидание', 1000, static fn (StagePolicy $policy): int => $policy->failureThreshold),
                    self::override('layout_threshold', 'Порог отказов структуры подряд → остановка', 1000, static fn (StagePolicy $policy): int => $policy->layoutThreshold),
                    self::override('retry_interval_minutes', 'Ожидание, мин', 1440, static fn (StagePolicy $policy): int => $policy->retryIntervalMinutes),
                ]),
        ]);
    }

    /** @param Closure(StagePolicy): int $default */
    private static function override(string $name, string $label, int $max, Closure $default): TextInput
    {
        return TextInput::make($name)
            ->label($label)
            ->integer()
            ->minValue(1)
            ->maxValue($max)
            ->nullable()
            ->placeholder(fn (?SourceCircuit $record): string => $record === null
                ? ''
                : 'по умолчанию '.$default(app(SourceControlPolicy::class)->stage($record->stage)));
    }
}
