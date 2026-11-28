<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Platform\ParsingRuleSets\Tables;

use App\Enums\Procurement\Parsing\AutoHealMode;
use App\Enums\Procurement\Process\Core\StageCode;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ParsingRuleSetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('platform.code')
                    ->label('Площадка')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('stage')
                    ->label('Стадия')
                    ->badge()
                    ->sortable(),
                TextColumn::make('registry_code')
                    ->label('Реестр')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('scope_key')
                    ->label('Scope')
                    ->searchable(),
                TextColumn::make('activeVersion.version')
                    ->label('Активная')
                    ->formatStateUsing(static fn (mixed $state): string => is_int($state) || is_string($state) ? 'v'.(string) $state : '—')
                    ->placeholder('—'),
                TextColumn::make('current_version_number')
                    ->label('Версий')
                    ->sortable(),
                TextColumn::make('auto_heal_mode')
                    ->label('Auto-heal')
                    ->badge(),
                IconColumn::make('is_enabled')
                    ->label('Вкл.')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->label('Обновлён')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('stage')
                    ->label('Стадия')
                    ->options([
                        StageCode::Listing->value => StageCode::Listing->value,
                        StageCode::Detail->value => StageCode::Detail->value,
                        StageCode::StatusRefresh->value => StageCode::StatusRefresh->value,
                    ]),
                SelectFilter::make('auto_heal_mode')
                    ->label('Режим самокоррекции')
                    ->options([
                        AutoHealMode::Disabled->value => AutoHealMode::Disabled->value,
                        AutoHealMode::Propose->value => AutoHealMode::Propose->value,
                        AutoHealMode::AutoPromoteFallback->value => AutoHealMode::AutoPromoteFallback->value,
                    ]),
                TernaryFilter::make('is_enabled')
                    ->label('Включён'),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
