<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\Link\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Platform\Models\Crawling\Link;

final class LinksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordTitle(fn (Link $record): string => $record->reg_number ?: "Ссылка #{$record->id}")
            ->columns([
                TextColumn::make('platform.name')
                    ->label('Платформа')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('reg_number')
                    ->label('Рег. номер')
                    ->searchable(),
                TextColumn::make('detail_url')
                    ->label('URL')
                    ->limit(50)
                    ->url(fn ($state) => $state, shouldOpenInNewTab: true),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge(),
                TextColumn::make('published_at')
                    ->label('Опубликовано')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('platform_id')
                    ->relationship(name: 'platform', titleAttribute: 'name')
                    ->label('Платформа')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
