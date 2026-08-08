<?php

namespace App\Filament\Resources\Procurement\Ai\Analyses\Schemas;

use App\Filament\Resources\Procurement\Ai\Fixtures\FixtureResource;
use App\Filament\Resources\Procurement\Estimates\EstimateResource;
use App\Models\Procurement\Ai\Analysis;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AnalysisInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
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
            ]);
    }
}
