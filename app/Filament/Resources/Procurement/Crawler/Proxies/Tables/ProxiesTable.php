<?php

namespace App\Filament\Resources\Procurement\Crawler\Proxies\Tables;

use App\Models\Procurement\Crawler\Proxy;
use App\Repositories\Procurement\Proxy\ProxyHealthStoreRepository;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ProxiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('port')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('country')
                    ->sortable(),
                TextColumn::make('scopes')
                    ->badge(),
                IconColumn::make('is_active')
                    ->boolean(),
                TextColumn::make('fails_count')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('disabled_reason')
                    ->label('Причина отключения')
                    ->toggleable(),
                TextColumn::make('disabled_at')
                    ->label('Отключён')
                    ->dateTime()
                    ->sortable(),
                // Состояние breaker'а — выводится, не хранится третьей колонкой (research/02 §7):
                // `open_until` в будущем → узел временно выведен из выдачи.
                IconColumn::make('open_until')
                    ->label('В breaker\'е')
                    ->getStateUsing(fn (Proxy $record): bool => $record->open_until !== null && $record->open_until->isFuture())
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('disabled')
                    ->label('Отключённые')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('disabled_at'),
                        false: fn ($query) => $query->whereNull('disabled_at'),
                    ),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('reactivate')
                    ->label('Включить обратно')
                    ->color('success')
                    ->authorize('update')
                    ->requiresConfirmation()
                    ->visible(fn (Proxy $record): bool => ! $record->is_active)
                    ->action(function (Proxy $record): void {
                        app(ProxyHealthStoreRepository::class)->reactivate($record);
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
