<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\DocumentAnalysis\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Platform\Models\Crawling\LinkAttachment;
use Platform\Models\DocumentAnalysis\DocumentAnalysis;

final class DocumentAnalysesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordTitle(fn (DocumentAnalysis $record): string => $record->name ?: "Анализ #{$record->id}")
            ->columns([
                TextColumn::make('documentAnalysable.name')
                    ->label('Источник')
                    ->formatStateUsing(fn (?string $state, DocumentAnalysis $record): string => $state ?? $record->document_analysable_type.' #'.$record->document_analysable_id)
                    ->searchable()
                    ->sortable()
                    ->limit(40),
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->limit(40),
                TextColumn::make('extension')
                    ->label('Расширение'),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => 'Ожидает',
                        'processing' => 'В обработке',
                        'completed' => 'Завершён',
                        'failed' => 'Ошибка',
                        default => $state,
                    }),
                TextColumn::make('completed_at')
                    ->label('Завершён')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('attempts')
                    ->label('Попытки')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'pending' => 'Ожидает',
                        'processing' => 'В обработке',
                        'completed' => 'Завершён',
                        'failed' => 'Ошибка',
                    ]),
                SelectFilter::make('document_analysable_type')
                    ->label('Тип источника')
                    ->options([
                        LinkAttachment::class => 'Вложение ссылки',
                    ]),
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
