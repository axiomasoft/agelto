<?php

namespace App\Filament\Resources\Procurement\Procurements;

use App\Filament\Resources\Procurement\Procurements\Pages\ListProcurements;
use App\Filament\Resources\Procurement\Procurements\Pages\ViewProcurement;
use App\Filament\Resources\Procurement\Procurements\Schemas\ProcurementInfolist;
use App\Filament\Resources\Procurement\Procurements\Tables\ProcurementsTable;
use App\Models\Procurement\Procurement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Реестр закупок — read-only (index + view). Продукт пайплайна: оператор
 * ревизует, не редактирует. Карточка с таб-ами (события/редакции/позиции/
 * протоколы/результаты) — фаза 2.
 */
class ProcurementResource extends Resource
{
    protected static ?string $model = Procurement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Закупки';

    protected static ?int $navigationSort = 10;

    public static function infolist(Schema $schema): Schema
    {
        return ProcurementInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProcurementsTable::configure($table);
    }

    // Eager-load связи, показанной в таблице (registry.name) — против N+1 в реестре.
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('registry');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProcurements::route('/'),
            'view' => ViewProcurement::route('/{record}'),
        ];
    }
}
