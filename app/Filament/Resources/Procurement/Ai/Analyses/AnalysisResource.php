<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\Analyses;

use App\Filament\Resources\Procurement\Ai\Analyses\Pages\ListAnalyses;
use App\Filament\Resources\Procurement\Ai\Analyses\Pages\ViewAnalysis;
use App\Filament\Resources\Procurement\Ai\Analyses\RelationManagers\CorrectionsRelationManager;
use App\Filament\Resources\Procurement\Ai\Analyses\Schemas\AnalysisInfolist;
use App\Filament\Resources\Procurement\Ai\Analyses\Tables\AnalysesTable;
use App\Models\Procurement\Ai\Analysis;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * AI-анализы (screening/extraction) — read-only реестр (index + view),. Продукт пайплайна:
 * оператор ревизует (проверено/коррекция/re-run через action'ы `ViewAnalysis`), не редактирует
 * поля напрямую — create/edit-страниц нет (тот же канон, что `ProcurementResource`/`RunResource`).
 */
class AnalysisResource extends Resource
{
    protected static ?string $model = Analysis::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Закупки';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Результаты анализа';

    protected static ?string $modelLabel = 'результат анализа';

    protected static ?string $pluralModelLabel = 'результаты анализа';

    public static function infolist(Schema $schema): Schema
    {
        return AnalysisInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AnalysesTable::configure($table);
    }

    // Eager-load связей, показанных в таблице/инфолисте (документ + кто проверил) — против N+1.
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['documentVersion.document.fixture', 'estimates', 'reviewedBy']);
    }

    public static function getRelations(): array
    {
        return [
            CorrectionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAnalyses::route('/'),
            'view' => ViewAnalysis::route('/{record}'),
        ];
    }
}
