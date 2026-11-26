<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Procurements;

use App\Enums\Procurement\Process\StageCode;
use App\Filament\Resources\Procurement\Procurements\Pages\ListProcurements;
use App\Filament\Resources\Procurement\Procurements\Pages\ViewProcurement;
use App\Filament\Resources\Procurement\Procurements\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Procurement\Procurements\RelationManagers\RevisionsRelationManager;
use App\Filament\Resources\Procurement\Procurements\Schemas\ProcurementInfolist;
use App\Filament\Resources\Procurement\Procurements\Tables\ProcurementsTable;
use App\Models\Procurement\Procurement;
use AzGuard\Filament\Concerns\AuthorizesResource;
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
    use AuthorizesResource;

    protected static ?string $azguardKey = 'procurement';

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
    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected static function modifyEloquentQuery(Builder $query): Builder
    {
        /** @var Builder<Procurement> $baseQuery */
        $baseQuery = $query;

        $latestDetailWork = DB::table('prc_process_work')
            ->select(['state as detail_work_state', 'reason_code as detail_work_reason_code', 'due_at as detail_work_due_at'])
            ->whereColumn(first: 'procurement_id', operator: '=', second: 'prc_procurements.id')
            ->where(column: 'stage', operator: '=', value: StageCode::Detail->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(1);

        $latestPublishedSnapshot = DB::table('prc_detail_snapshots')
            ->select('created_at as detail_fetched_at')
            ->whereColumn(first: 'procurement_id', operator: '=', second: 'prc_procurements.id')
            ->where(column: 'part_key', operator: 'like', value: '%:published')
            ->where(column: 'completeness', operator: '=', value: 'complete')
            ->orderByDesc('created_at')
            ->limit(1);

        /** @var Builder<Model> $result */
        $result = $baseQuery->publicPlatform()->with('registry.platform')
            ->addSelect(['prc_procurements.*', 'detail_work.*', 'published_snapshot.detail_fetched_at'])
            ->leftJoinLateral(query: $latestDetailWork, as: 'detail_work')
            ->leftJoinLateral(query: $latestPublishedSnapshot, as: 'published_snapshot')
            ->withCasts(['detail_fetched_at' => 'datetime']);

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
