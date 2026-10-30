<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Crawler\SourceCircuits\Tables;

use App\Filament\Resources\Procurement\Process\ControlScopes\ControlScopeResource;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Historical evidence only. These counters never describe current send eligibility. */
final class SourceCircuitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->heading('История источников — прежние записи, допуск ими не управляется')
            ->columns([
                TextColumn::make('platform.name')->label('Площадка'),
                TextColumn::make('stage')->label('Прежний этап'),
                TextColumn::make('consecutive_failures')->label('Историческая серия отказов')->visibleFrom('md'),
                TextColumn::make('consecutive_layout_failures')->label('Историческая серия ошибок разметки')->visibleFrom('md'),
                TextColumn::make('updated_at')->label('Последнее изменение прежней записи')->dateTime(),
            ])
            ->recordUrl(null)
            ->recordActions([])
            ->toolbarActions([
                Action::make('process_controls')->label('Действующее управление процессами')->url(ControlScopeResource::getUrl()),
            ]);
    }
}
