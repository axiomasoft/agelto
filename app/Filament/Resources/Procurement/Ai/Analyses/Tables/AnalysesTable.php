<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\Analyses\Tables;

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\EstimateCardinality;
use App\Enums\Procurement\Ai\ReviewStatus;
use App\Enums\Procurement\Ai\ScreeningDecisionReason;
use App\Enums\Procurement\Ai\ScreeningDisposition;
use App\Models\Procurement\Ai\Analysis;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AnalysesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('documentVersion.document.title')
                    ->label('Документ')
                    ->limit(60)
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('step')
                    ->badge(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('document_type')
                    ->label('Тип')
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('screening_cardinality')
                    ->label('Смет')
                    ->state(static fn (Analysis $record): ?string => $record->storedScreeningResult()?->estimateCardinality->value)
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('screening_confidence')
                    ->label('Уверенность')
                    ->state(static fn (Analysis $record): ?string => self::confidence($record))
                    ->placeholder('—'),
                TextColumn::make('screening_disposition')
                    ->label('Решение')
                    ->state(static fn (Analysis $record): ?string => $record->storedScreeningResult()?->decision->disposition->value)
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('screening_reason')
                    ->label('Причина')
                    ->state(static fn (Analysis $record): ?string => $record->storedScreeningResult()?->decision->reason->value)
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('screening_summary')
                    ->label('Кратко')
                    ->state(static fn (Analysis $record): ?string => $record->storedScreeningResult()?->summary)
                    ->limit(80)
                    ->placeholder('—')
                    ->searchable(query: static fn (Builder $query, string $search): Builder => $query->withScreeningSearch($search)),
                TextColumn::make('screening_tags')
                    ->label('Теги')
                    ->state(static fn (Analysis $record): ?string => self::tags($record))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('review_status')
                    ->badge(),
                TextColumn::make('screening_lifecycle')
                    ->label('Состояние')
                    ->state(static fn (Analysis $record): string => self::lifecycle($record))
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('parent_analysis_id')
                    ->label('Re-run из')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('step')
                    ->options(collect(AnalysisStep::cases())->pluck('value', 'value')->all()),
                SelectFilter::make('status')
                    ->options(collect(AnalysisStatus::cases())->pluck('value', 'value')->all()),
                SelectFilter::make('review_status')
                    ->options(collect(ReviewStatus::cases())->pluck('value', 'value')->all()),
                SelectFilter::make('screening_document_type')
                    ->label('Тип screening')
                    ->options(collect(AiDocumentType::cases())->pluck('value', 'value')->all())
                    ->query(static fn (Builder $query, array $data): Builder => ($type = AiDocumentType::tryFrom($data['value'] ?? '')) === null
                        ? $query
                        : $query->withScreeningDocumentType($type)),
                SelectFilter::make('screening_cardinality')
                    ->label('Количество смет')
                    ->options(collect(EstimateCardinality::cases())->pluck('value', 'value')->all())
                    ->query(static fn (Builder $query, array $data): Builder => ($cardinality = EstimateCardinality::tryFrom($data['value'] ?? '')) === null
                        ? $query
                        : $query->withEstimateCardinality($cardinality)),
                SelectFilter::make('screening_disposition')
                    ->label('Решение screening')
                    ->options(collect(ScreeningDisposition::cases())->pluck('value', 'value')->all())
                    ->query(static fn (Builder $query, array $data): Builder => ($disposition = ScreeningDisposition::tryFrom($data['value'] ?? '')) === null
                        ? $query
                        : $query->withScreeningDisposition($disposition)),
                SelectFilter::make('screening_reason')
                    ->label('Причина решения')
                    ->options(collect(ScreeningDecisionReason::cases())->pluck('value', 'value')->all())
                    ->query(static fn (Builder $query, array $data): Builder => ($reason = ScreeningDecisionReason::tryFrom($data['value'] ?? '')) === null
                        ? $query
                        : $query->withScreeningDecisionReason($reason)),
                Filter::make('screening_tag')
                    ->label('Точный тег')
                    ->schema([
                        TextInput::make('value')
                            ->label('Тег')
                            ->maxLength(64),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->withScreeningTag((string) $data['value'])
                        : $query),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([
                //
            ]);
    }

    private static function confidence(Analysis $record): ?string
    {
        $confidence = $record->storedScreeningResult()?->confidence;

        return $confidence === null ? null : number_format($confidence * 100, 1).'%';
    }

    private static function tags(Analysis $record): ?string
    {
        $tags = $record->storedScreeningResult()?->tags ?? [];

        return $tags === [] ? null : implode(', ', $tags);
    }

    private static function lifecycle(Analysis $record): string
    {
        if ($record->is_shadow) {
            return 'теневая';
        }

        return $record->superseded_at === null ? 'активная' : 'заменена';
    }
}
