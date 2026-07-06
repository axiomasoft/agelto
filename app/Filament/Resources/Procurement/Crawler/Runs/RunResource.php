<?php

namespace App\Filament\Resources\Procurement\Crawler\Runs;

use App\Filament\Resources\Procurement\Crawler\Runs\Pages\ListRuns;
use App\Filament\Resources\Procurement\Crawler\Runs\Pages\ViewRun;
use App\Filament\Resources\Procurement\Crawler\Runs\Schemas\RunInfolist;
use App\Filament\Resources\Procurement\Crawler\Runs\Tables\RunsTable;
use App\Models\Procurement\Crawler\Run;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Прогоны краулера — read-only наблюдаемость (index + view). Операторских
 * мутаций нет: create/edit-страницы не заводим, а роли не получают
 * create/update/delete на runs (гейт AzGuard закрывает действия).
 * Ручное возобновление/сброс курсора — отдельная фаза.
 */
class RunResource extends Resource
{
    protected static ?string $model = Run::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Краулинг';

    protected static ?int $navigationSort = 60;

    public static function infolist(Schema $schema): Schema
    {
        return RunInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RunsTable::configure($table);
    }

    // Eager-load связи, показанной в таблице (crawler.name) — против N+1 в листинге.
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('crawler');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRuns::route('/'),
            'view' => ViewRun::route('/{record}'),
        ];
    }
}
