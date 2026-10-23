<?php

namespace App\Filament\Resources\Procurement\Ai\LlmRoutes\Tables;

use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Filament\Support\LlmPresentation;
use App\Models\Llm\LlmRoute;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AiRoutesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('document_type')
                    ->label('Тип документа')
                    ->formatStateUsing(static function (string $state): string {
                        $documentType = AiDocumentType::tryFrom($state) ?? DocumentTypeScope::tryFrom($state);

                        return $documentType === null ? $state : LlmPresentation::documentType($documentType);
                    }),
                TextColumn::make('purpose')
                    ->label('Задача')
                    ->formatStateUsing(static function (string $state, LlmRoute $record): string {
                        $documentTypeValue = $record->getAttribute('document_type');

                        if (! is_string($documentTypeValue)) {
                            return $state;
                        }

                        $documentType = AiDocumentType::tryFrom($documentTypeValue) ?? DocumentTypeScope::tryFrom($documentTypeValue);

                        if ($documentType === null) {
                            return $state;
                        }

                        return LlmPresentation::purpose(app(DocumentAnalyzerRegistry::class)->purposeFor($documentType, $state));
                    }),
                TextColumn::make('provider.code')
                    ->label('Провайдер')
                    ->searchable()
                    ->wrapHeader(),
                TextColumn::make('model')
                    ->label('Модель')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('priority')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_active')
                    ->boolean(),
                IconColumn::make('shadow')
                    ->boolean()
                    ->visibleFrom('md'),
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
