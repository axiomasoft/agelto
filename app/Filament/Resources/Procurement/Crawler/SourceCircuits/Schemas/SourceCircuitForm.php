<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Crawler\SourceCircuits\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class SourceCircuitForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Брейкер источника')
                ->description('Подряд идущие отказы блоков сверх порога ставят весь источник на паузу; через интервал автоповтора пробный блок решает, продолжать ли обход. Единичный отказ — инцидент и пропуск блока.')
                ->schema([
                    Toggle::make('enabled')
                        ->label('Автоматическая пауза при массовых отказах')
                        ->helperText('Выключено: отказы только записываются инцидентами, источник не останавливается. Ручная пауза действует всегда.'),
                    TextInput::make('failure_threshold')
                        ->label('Порог отказов подряд')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(1000)
                        ->required(),
                    TextInput::make('retry_interval_minutes')
                        ->label('Автоповтор через, мин')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(1440)
                        ->required(),
                ]),
        ]);
    }
}
