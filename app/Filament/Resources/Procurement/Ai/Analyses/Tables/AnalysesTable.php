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
use App\Filament\Resources\Procurement\Ai\Analyses\AnalysisResource;
use App\Filament\Support\LlmPresentation;
use App\Models\Procurement\Ai\Analysis;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AnalysesTable
{
    public static function configure(Table $table): Table
    {
        // Строки фильтруются запросом ресурса, а URL карточки заново проверяет доступ.
        $viewUrl = static fn (Analysis $record): string => AnalysisResource::getUrl(name: 'view', parameters: ['record' => $record]);

        return $table
            ->recordUrl($viewUrl)
            ->recordAction(null)
            ->columns([
                TextColumn::make('documentVersion.document.title')
                    ->label('Документ')
                    ->wrapHeader()
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (?string $state): ?string => $state)
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
                    ->placeholder('—')
                    ->visibleFrom('md'),
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
                    ->wrap()
                    ->lineClamp(2)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('—')
                    ->searchable(query: static function (Builder $query, string $search): Builder {
                        /** @var Builder<Analysis> $query */
                        return $query->withScreeningSearch($search);
                    }),
                TextColumn::make('screening_tags')
                    ->label('Теги')
                    ->state(static fn (Analysis $record): ?string => self::tags($record))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('review_status')
                    ->badge()
                    ->visibleFrom('md'),
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
                    ->options(self::enumOptions(AnalysisStep::cases())),
                SelectFilter::make('status')
                    ->options(self::enumOptions(AnalysisStatus::cases())),
                SelectFilter::make('review_status')
                    ->options(self::enumOptions(ReviewStatus::cases())),
                SelectFilter::make('screening_document_type')
                    ->label('Тип screening')
                    ->options(LlmPresentation::documentTypeOptions())
                    ->query(static function (Builder $query, array $data): Builder {
                        /** @var Builder<Analysis> $query */
                        $type = AiDocumentType::tryFrom(self::filterValue($data));

                        return $type === null ? $query : $query->withScreeningDocumentType($type);
                    }),
                SelectFilter::make('screening_cardinality')
                    ->label('Количество смет')
                    ->options(self::enumOptions(EstimateCardinality::cases()))
                    ->query(static function (Builder $query, array $data): Builder {
                        /** @var Builder<Analysis> $query */
                        $cardinality = EstimateCardinality::tryFrom(self::filterValue($data));

                        return $cardinality === null ? $query : $query->withEstimateCardinality($cardinality);
                    }),
                SelectFilter::make('screening_disposition')
                    ->label('Решение screening')
                    ->options(self::enumOptions(ScreeningDisposition::cases()))
                    ->query(static function (Builder $query, array $data): Builder {
                        /** @var Builder<Analysis> $query */
                        $disposition = ScreeningDisposition::tryFrom(self::filterValue($data));

                        return $disposition === null ? $query : $query->withScreeningDisposition($disposition);
                    }),
                SelectFilter::make('screening_reason')
                    ->label('Причина решения')
                    ->options(self::enumOptions(ScreeningDecisionReason::cases()))
                    ->query(static function (Builder $query, array $data): Builder {
                        /** @var Builder<Analysis> $query */
                        $reason = ScreeningDecisionReason::tryFrom(self::filterValue($data));

                        return $reason === null ? $query : $query->withScreeningDecisionReason($reason);
                    }),
                Filter::make('screening_tag')
                    ->label('Точный тег')
                    ->schema([
                        TextInput::make('value')
                            ->label('Тег')
                            ->maxLength(64),
                    ])
                    ->query(static function (Builder $query, array $data): Builder {
                        /** @var Builder<Analysis> $query */
                        $tag = self::filterValue($data);

                        return filled($tag)
                            ? $query->withScreeningTag($tag)
                            : $query;
                    }),
            ])
            ->recordActions([
                Action::make('view')
                    ->label(__('filament-actions::view.single.label'))
                    ->icon(Heroicon::Eye)
                    ->color('gray')
                    ->url($viewUrl)
                    ->authorize(true),
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
        $screening = $record->storedScreeningResult();
        $tags = $screening === null ? [] : $screening->tags;

        if ($tags === []) {
            return null;
        }

        return implode(', ', $tags);
    }

    private static function lifecycle(Analysis $record): string
    {
        if ($record->is_shadow) {
            return 'теневая';
        }

        return $record->superseded_at === null ? 'активная' : 'заменена';
    }

    /** @param list<BackedEnum> $cases
     * @return array<string, string>
     */
    private static function enumOptions(array $cases): array
    {
        $options = [];

        foreach ($cases as $case) {
            if (! is_string($case->value)) {
                throw new \RuntimeException('Фильтр Filament ожидает строковые enum values.');
            }

            $options[$case->value] = $case->value;
        }

        return $options;
    }

    /** @param array<mixed> $data */
    private static function filterValue(array $data): string
    {
        $value = $data['value'] ?? '';

        return is_string($value) ? $value : '';
    }
}
