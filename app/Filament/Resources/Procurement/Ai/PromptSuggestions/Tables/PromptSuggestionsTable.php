<?php

namespace App\Filament\Resources\Procurement\Ai\PromptSuggestions\Tables;

use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\PromptSuggestionKind;
use App\Enums\Procurement\Ai\PromptSuggestionStatus;
use App\Filament\Resources\Procurement\Ai\PromptSuggestions\PromptSuggestionResource;
use App\Filament\Support\LlmPresentation;
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
                    ->searchable()
                    ->wrapHeader()
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (?string $state): ?string => $state),
                TextColumn::make('base_version')
                    ->label('Базовая версия')
                    ->placeholder('-'),
                TextColumn::make('document_type')
                    ->label('Тип документа')
                    ->visibleFrom('md'),
                TextColumn::make('analysis_step')
                    ->label('Стадия')
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('origin')
                    ->label('Происхождение')
                    ->state(static fn (PromptSuggestion $record): string => PromptSuggestionResource::origin($record))
                    ->placeholder('-')
                    ->visibleFrom('md'),
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
                    ->options(self::enumOptions(PromptSuggestionStatus::cases()))
                    ->default(PromptSuggestionStatus::Pending->value),
                SelectFilter::make('kind')
                    ->label('Форма')
                    ->options(self::enumOptions(PromptSuggestionKind::cases())),
                SelectFilter::make('document_type')
                    ->label('Тип документа')
                    ->options(self::documentTypeOptions()),
                SelectFilter::make('analysis_step')
                    ->label('Стадия')
                    ->options(self::enumOptions(AnalysisStep::cases())),
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
        $options = LlmPresentation::documentTypeOptions();
        $options[DocumentTypeScope::Any->value] = LlmPresentation::documentType(DocumentTypeScope::Any);

        return $options;
    }

    /**
     * @param  list<\BackedEnum>  $cases
     * @return array<string, string>
     */
    private static function enumOptions(array $cases): array
    {
        $options = collect($cases)->mapWithKeys(
            static fn (\BackedEnum $case): array => [$case->value => $case->value],
        )->all();

        /** @var array<string, string> $options */
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
