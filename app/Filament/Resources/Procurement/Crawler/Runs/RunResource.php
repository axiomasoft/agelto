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
 * Прогоны краулера: index + view, БЕЗ create/edit/delete-страниц (профиль и прогон не
 * редактируются напрямую). Единственная мутация — операторские действия «Остановить»/«Отменить»
 * (P3.4, `RequestRunStopAction`) поверх кооперативного стоп-гейта; они не требуют своего
 * create/update/delete на ресурсе — авторизуются строкой ability `RunPermission::Update`
 * (канон `ViewFixture`), а не через `authorize()` ресурса.
 */
class RunResource extends Resource
{
    protected static ?string $model = Run::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Сбор данных';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Запуски сбора';

    protected static ?string $modelLabel = 'запуск сбора';

    protected static ?string $pluralModelLabel = 'запуски сбора';

    public static function infolist(Schema $schema): Schema
    {
        return RunInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RunsTable::configure($table);
    }

    /**
     * Eager-load связи (`crawler.name`) + агрегаты по блокам БАЗОЙ (`withCount`/`withSum`), не
     * выборкой всех строк в PHP: `prc_fetches` партиционирована и растёт на каждый блок каждого
     * прогона (Implementation Rule 1, P3.6).
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('crawler')
            ->withCount('fetches')
            ->withSum('fetches as fetches_rows_new_sum', 'rows_new')
            ->withSum('fetches as fetches_rows_updated_sum', 'rows_updated');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRuns::route('/'),
            'view' => ViewRun::route('/{record}'),
        ];
    }
}
