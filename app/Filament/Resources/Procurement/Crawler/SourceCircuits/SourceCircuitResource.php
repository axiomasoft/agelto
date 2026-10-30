<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Crawler\SourceCircuits;

use App\Filament\Resources\Procurement\Crawler\SourceCircuits\Pages\ListSourceCircuits;
use App\Filament\Resources\Procurement\Crawler\SourceCircuits\Tables\SourceCircuitsTable;
use App\Models\Procurement\Crawler\SourceCircuit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Historical read-only rows retained until the acquisition UI cutover. Admission uses process controls and network health. */
final class SourceCircuitResource extends Resource
{
    protected static ?string $model = SourceCircuit::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Сбор данных';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Источники: история';

    protected static ?string $modelLabel = 'историческая запись источника';

    protected static ?string $pluralModelLabel = 'история источников';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return SourceCircuitsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSourceCircuits::route('/'),
        ];
    }
}
