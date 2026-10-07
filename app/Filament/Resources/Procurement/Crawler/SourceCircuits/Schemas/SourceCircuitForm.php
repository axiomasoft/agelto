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
            Section::make('Брейкер этапа')
                ->description('Порог отказов подряд — этап ждёт заданное число минут и затем просто продолжает работу. Единичный отказ — инцидент и пропуск.')
                ->schema([
                    Toggle::make('enabled')
                        ->label('Автоматическая пауза при массовых отказах')
                        ->helperText('Выключено: отказы только записываются инцидентами, этап не останавливается. Ручная пауза действует всегда.'),
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
