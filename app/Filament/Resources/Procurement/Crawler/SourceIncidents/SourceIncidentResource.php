<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Crawler\SourceIncidents;

use App\Filament\Resources\Procurement\Crawler\SourceIncidents\Pages\ListSourceIncidents;
use App\Filament\Resources\Procurement\Crawler\SourceIncidents\Tables\SourceIncidentsTable;
use App\Models\Procurement\Crawler\SourceIncident;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Журнал инцидентов источников: пропущенные блоки (где именно — курсор, параметры запроса,
 * fetch), паузы/возобновления, действия операторов. Разбор пропусков — вручную; отметка
 * «Разобрано» — единственное изменение записи.
 */
final class SourceIncidentResource extends Resource
{
    protected static ?string $model = SourceIncident::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Сбор данных';

    protected static ?int $navigationSort = 41;

    protected static ?string $navigationLabel = 'Источники: инциденты';

    protected static ?string $modelLabel = 'инцидент источника';

    protected static ?string $pluralModelLabel = 'инциденты источников';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    public static function table(Table $table): Table
    {
        return SourceIncidentsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $open = SourceIncident::query()->whereNull('acknowledged_at')->where('severity', '!=', 'info')->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getPages(): array
    {
        return ['index' => ListSourceIncidents::route('/')];
    }
}
