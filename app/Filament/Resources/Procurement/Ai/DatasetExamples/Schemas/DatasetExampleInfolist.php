<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\DatasetExamples\Schemas;

use App\Filament\Resources\Procurement\Ai\Analyses\AnalysisResource;
use App\Filament\Resources\Procurement\Ai\Fixtures\FixtureResource;
use App\Models\Procurement\Ai\DatasetExample;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class DatasetExampleInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('fixture.code')->label('Код эталона')->placeholder('—')
                ->url(fn (DatasetExample $record): ?string => $record->fixture === null ? null : FixtureResource::getUrl('view', ['record' => $record->fixture])),
            TextEntry::make('documentVersion.document.title')->label('Документ')->placeholder('—')->columnSpanFull(),
            TextEntry::make('analysis.id')->label('Анализ')->placeholder('—')
                ->url(fn (DatasetExample $record): ?string => $record->analysis === null ? null : AnalysisResource::getUrl('view', ['record' => $record->analysis])),
            TextEntry::make('task')->label('Задача')->badge(),
            TextEntry::make('document_type')->label('Тип документа')->badge(),
            TextEntry::make('source')->label('Источник')->badge(),
            TextEntry::make('version')->label('Версия'),
            TextEntry::make('verifiedBy.name')->label('Проверил')->placeholder('—'),
            TextEntry::make('verified_at')->label('Проверено')->dateTime()->placeholder('—'),
            TextEntry::make('created_at')->label('Создан')->dateTime(),
        ]);
    }
}
