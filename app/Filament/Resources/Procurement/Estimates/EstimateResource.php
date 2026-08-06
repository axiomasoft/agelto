<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Estimates;

use App\Filament\Resources\Procurement\Estimates\Pages\ListEstimates;
use App\Filament\Resources\Procurement\Estimates\Pages\ViewEstimate;
use App\Filament\Resources\Procurement\Estimates\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\Procurement\Estimates\Schemas\EstimateInfolist;
use App\Filament\Resources\Procurement\Estimates\Tables\EstimatesTable;
use App\Models\Procurement\Estimate\Estimate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Витрина смет с пометками неопределённости позиции (P8.5, D137) — read-only реестр (index +
 * view): оператор смотрит, КАКАЯ позиция под сомнением и ПОЧЕМУ, не редактирует (D137, OQ-10).
 * Create/edit/delete-страниц нет — тот же канон, что `ProcurementResource`/`AnalysisResource`.
 */
class EstimateResource extends Resource
{
    protected static ?string $model = Estimate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|\UnitEnum|null $navigationGroup = 'Закупки';

    protected static ?int $navigationSort = 15;

    public static function infolist(Schema $schema): Schema
    {
        return EstimateInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EstimatesTable::configure($table);
    }

    // Счётчик "помечено N из M" — агрегатом в основном запросе (withCount), не выборкой позиций
    // в цикле (Implementation Rules P8.5 п.2): список открывается константным числом запросов
    // независимо от числа позиций в смете.
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['procurement', 'documentVersion.document'])
            ->withCount([
                'items as items_count',
                'items as marked_items_count' => fn (Builder $query): Builder => $query->whereNotNull('quality'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEstimates::route('/'),
            'view' => ViewEstimate::route('/{record}'),
        ];
    }
}
