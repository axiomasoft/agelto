<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Platform\Registries\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RegistriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('uuid')
                    ->label('UUID')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('platform.name')
                    ->searchable()
                    ->label('Площадка')
                    ->wrapHeader()
                    ->visibleFrom('md'),
                TextColumn::make('code')
                    ->searchable()
                    ->wrapHeader(),
                TextColumn::make('name')
                    ->searchable()
                    ->wrapHeader()
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (?string $state): ?string => $state),
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
