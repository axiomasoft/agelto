<?php

namespace App\Filament\Resources\Procurement\Platform\Platforms\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlatformsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('uuid')
                    ->label('UUID')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('code')
                    ->searchable()
                    ->wrapHeader(),
                TextColumn::make('name')
                    ->searchable()
                    ->wrapHeader()
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (?string $state): ?string => $state),
                TextColumn::make('base_url')
                    ->searchable()
                    ->wrapHeader()
                    ->lineClamp(2)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->visibleFrom('md'),
                IconColumn::make('is_active')
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
                //
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
