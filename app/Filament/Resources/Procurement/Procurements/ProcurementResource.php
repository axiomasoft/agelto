<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Procurements;

use App\Filament\Resources\Procurement\Procurements\Pages\ListProcurements;
use App\Filament\Resources\Procurement\Procurements\Pages\ViewProcurement;
use App\Filament\Resources\Procurement\Procurements\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Procurement\Procurements\RelationManagers\RevisionsRelationManager;
use App\Filament\Resources\Procurement\Procurements\Schemas\ProcurementInfolist;
use App\Filament\Resources\Procurement\Procurements\Tables\ProcurementsTable;
use App\Models\Procurement\Procurement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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

    protected static ?string $navigationLabel = 'Закупки';

    protected static ?string $modelLabel = 'закупка';

    protected static ?string $pluralModelLabel = 'закупки';

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
        /** @var Builder<Procurement> $baseQuery */
        $baseQuery = parent::getEloquentQuery();

        $query = $baseQuery->publicPlatform()->with('registry.platform')->addSelect([
            'detail_fetched_at' => DB::table('prc_detail_snapshots')->selectRaw('max(created_at)')
                ->whereColumn('procurement_id', 'prc_procurements.id')->where('part_key', 'like', '%:published')
                ->where('completeness', 'complete'),
        ])->withCasts(['detail_fetched_at' => 'datetime']);
        foreach (['state', 'reason_code', 'due_at'] as $field) {
            $query->addSelect(['detail_work_'.$field => DB::table('prc_process_work')
                ->select($field)->whereColumn('procurement_id', 'prc_procurements.id')->where('stage', 'detail')
                ->orderByDesc('created_at')->orderByDesc('id')->limit(1)]);
        }

        /** @var Builder<Model> $result */
        $result = $query;

        return $result;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProcurements::route('/'),
            'view' => ViewProcurement::route('/{record}'),
        ];
    }

    /** @return array<class-string> */
    public static function getRelations(): array
    {
        return [
            EventsRelationManager::class,
            RevisionsRelationManager::class,
        ];
    }
}
