<?php

namespace App\Filament\Resources\Procurement\Platform\Registries;

use App\Filament\Resources\Procurement\Platform\Registries\Pages\CreateRegistry;
use App\Filament\Resources\Procurement\Platform\Registries\Pages\EditRegistry;
use App\Filament\Resources\Procurement\Platform\Registries\Pages\ListRegistries;
use App\Filament\Resources\Procurement\Platform\Registries\Schemas\RegistryForm;
use App\Filament\Resources\Procurement\Platform\Registries\Tables\RegistriesTable;
use App\Models\Procurement\Platform\Registry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RegistryResource extends Resource
{
    protected static ?string $model = Registry::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Площадки';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Реестры закупок';

    protected static ?string $modelLabel = 'реестр закупок';

    protected static ?string $pluralModelLabel = 'реестры закупок';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return RegistryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RegistriesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRegistries::route('/'),
            'create' => CreateRegistry::route('/create'),
            'edit' => EditRegistry::route('/{record}/edit'),
        ];
    }
}
