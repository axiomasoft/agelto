<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\Analyses\Schemas;

use App\Filament\Resources\Procurement\Ai\Analyses\AnalysisResource;
use App\Filament\Resources\Procurement\Ai\Fixtures\FixtureResource;
use App\Filament\Resources\Procurement\Estimates\EstimateResource;
use App\Models\Procurement\Ai\Analysis;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AnalysisInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Документ и состояние анализа')
                    ->description('Здесь видны исходный документ, текущая стадия и операторская проверка результата.')
                    ->schema([
                        TextEntry::make('documentVersion.document.title')
                            ->label('Документ')
                            ->placeholder('-')
                            ->columnSpanFull(),
                        TextEntry::make('documentVersion.document.fixture.code')
                            ->label('Эталон')
                            ->placeholder('—')
                            ->url(fn (Analysis $record): ?string => $record->documentVersion?->document?->fixture === null ? null : FixtureResource::getUrl('view', ['record' => $record->documentVersion->document->fixture])),
                        TextEntry::make('step')
                            ->badge(),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('document_type')
                            ->placeholder('-'),
                        TextEntry::make('review_status')
                            ->badge(),
                        TextEntry::make('reviewedBy.name')
                            ->label('Проверил')
                            ->placeholder('-'),
                        TextEntry::make('reviewed_at')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('operator_comment')
                            ->placeholder('-')
                            ->columnSpanFull(),
                        TextEntry::make('parent_analysis_id')
                            ->label('Предыдущий прогон (id)')
                            ->placeholder('-'),
                        TextEntry::make('superseded_at')
                            ->label('Заменён (дата)')
                            ->dateTime()
                            ->placeholder('-'),
                        TextEntry::make('screening_lifecycle')
                            ->label('Состояние записи')
                            ->state(static fn (Analysis $record): string => self::lifecycle($record))
                            ->badge(),
                    ])
                    ->columns(3),
                Section::make('Классификация screening')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('screening_summary')
                            ->label('Краткое содержание')
                            ->state(static fn (Analysis $record): ?string => $record->storedScreeningResult()?->summary)
                            ->placeholder('Нет v2-результата screening')
                            ->columnSpanFull(),
                        TextEntry::make('screening_cardinality')
                            ->label('Количество смет')
                            ->state(static fn (Analysis $record): ?string => $record->storedScreeningResult()?->estimateCardinality->value)
                            ->badge()
                            ->placeholder('—'),
                        TextEntry::make('screening_confidence')
                            ->label('Уверенность')
                            ->state(static fn (Analysis $record): ?string => self::confidence($record))
                            ->placeholder('—'),
                        TextEntry::make('screening_note')
                            ->label('Примечание классификации')
                            ->state(static fn (Analysis $record): ?string => $record->storedScreeningResult()?->classificationNote)
                            ->placeholder('—'),
                        RepeatableEntry::make('screening_tag_anchors')
                            ->label('Теги')
                            ->state(static fn (Analysis $record): array => self::tagAnchors($record))
                            ->schema([
                                TextEntry::make('tag')
                                    ->badge()
                                    ->url(static fn (string $state): string => AnalysisResource::getUrl('index', [
                                        'tableFilters' => ['screening_tag' => ['value' => $state]],
                                    ])),
                            ])
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
                Section::make('Решение backend')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('screening_disposition')
                            ->label('Распоряжение')
                            ->state(static fn (Analysis $record): ?string => $record->storedScreeningResult()?->decision->disposition->value)
                            ->badge()
                            ->placeholder('—'),
                        TextEntry::make('screening_reason')
                            ->label('Причина')
                            ->state(static fn (Analysis $record): ?string => $record->storedScreeningResult()?->decision->reason->value)
                            ->badge()
                            ->placeholder('—'),
                        TextEntry::make('screening_extraction_dispatched')
                            ->label('Разбор поставлен в очередь')
                            ->state(static fn (Analysis $record): ?string => self::extractionDispatched($record))
                            ->placeholder('—'),
                    ])
                    ->columnSpanFull(),
                Section::make('Результат и ошибки')
                    ->description('Ошибки и технический результат доступны для разбора, не изменяя историю анализа.')
                    ->schema([
                        TextEntry::make('last_error')
                            ->placeholder('-')
                            ->columnSpanFull(),
                        TextEntry::make('result')
                            ->label('Result (JSON)')
                            ->state(static fn (Analysis $record): ?string => $record->result === null
                                ? null
                                : json_encode($record->result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
                            ->placeholder('-')
                            ->columnSpanFull(),
                        RepeatableEntry::make('estimates')
                            ->label('Сметы')
                            ->schema([
                                TextEntry::make('ordinal')
                                    ->url(fn ($record): string => EstimateResource::getUrl('view', ['record' => $record])),
                                TextEntry::make('estimate_number')->placeholder('-'),
                                TextEntry::make('validation_status')->badge(),
                                TextEntry::make('rows_extracted'),
                                TextEntry::make('rows_expected'),
                            ])
                            ->columns(5)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    private static function confidence(Analysis $record): ?string
    {
        $confidence = $record->storedScreeningResult()?->confidence;

        return $confidence === null ? null : number_format($confidence * 100, 1).'%';
    }

    /**
     * @return list<array{tag: string}>
     */
    private static function tagAnchors(Analysis $record): array
    {
        $tags = $record->storedScreeningResult()?->tags ?? [];

        return array_map(static fn (string $tag): array => ['tag' => $tag], $tags);
    }

    private static function extractionDispatched(Analysis $record): ?string
    {
        $value = $record->storedScreeningResult()?->decision->extractionDispatched;

        return $value === null ? null : ($value ? 'да' : 'нет');
    }

    private static function lifecycle(Analysis $record): string
    {
        if ($record->is_shadow) {
            return 'теневая';
        }

        return $record->superseded_at === null ? 'активная' : 'заменена';
    }
}
