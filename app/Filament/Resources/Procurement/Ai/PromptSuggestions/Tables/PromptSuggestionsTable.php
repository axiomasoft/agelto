<?php

namespace App\Filament\Resources\Procurement\Ai\PromptSuggestions\Tables;

use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\AiDocumentType;
use App\Enums\Procurement\AnalysisStep;
use App\Enums\Procurement\PromptSuggestionKind;
use App\Enums\Procurement\PromptSuggestionStatus;
use App\Filament\Resources\Procurement\Ai\PromptSuggestions\PromptSuggestionResource;
use App\Models\Procurement\Ai\PromptSuggestion;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PromptSuggestionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(static fn (PromptSuggestionStatus $state): string => self::statusColor($state)),
                TextColumn::make('kind')
                    ->label('Форма')
                    ->badge(),
                TextColumn::make('prompt_key')
                    ->label('Ключ промта')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('base_version')
                    ->label('Базовая версия')
                    ->placeholder('-'),
                TextColumn::make('document_type')
                    ->label('Тип документа'),
                TextColumn::make('analysis_step')
                    ->label('Стадия')
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('origin')
                    ->label('Происхождение')
                    ->state(static fn (PromptSuggestion $record): string => PromptSuggestionResource::origin($record))
                    ->placeholder('-'),
                TextColumn::make('decidedBy.name')
                    ->label('Решил')
                    ->placeholder('-'),
                TextColumn::make('applied_version')
                    ->label('Применённая версия')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                // Очередь открывается на нерешённом: остальное — история.
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(collect(PromptSuggestionStatus::cases())->pluck('value', 'value')->all())
                    ->default(PromptSuggestionStatus::Pending->value),
                SelectFilter::make('kind')
                    ->label('Форма')
                    ->options(collect(PromptSuggestionKind::cases())->pluck('value', 'value')->all()),
                SelectFilter::make('document_type')
                    ->label('Тип документа')
                    ->options(self::documentTypeOptions()),
                SelectFilter::make('analysis_step')
                    ->label('Стадия')
                    ->options(collect(AnalysisStep::cases())->pluck('value', 'value')->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                //
            ]);
    }

    /**
     * Ось типа документа несёт и конкретные типы, и сентинел «любой» (`prompt_patch` пишет туда
     * скоуп своего шага) — фильтр обязан предлагать оба множества, иначе половина очереди
     * неотфильтровываема.
     *
     * @return array<string, string>
     */
    private static function documentTypeOptions(): array
    {
        $options = collect(AiDocumentType::cases())->pluck('value', 'value')->all();
        $options[DocumentTypeScope::Any->value] = DocumentTypeScope::Any->value;

        return $options;
    }

    private static function statusColor(PromptSuggestionStatus $status): string
    {
        return match ($status) {
            PromptSuggestionStatus::Pending => 'warning',
            PromptSuggestionStatus::Approved => 'info',
            PromptSuggestionStatus::Applied => 'success',
            PromptSuggestionStatus::Rejected => 'gray',
            PromptSuggestionStatus::Reverted => 'danger',
        };
    }
}
