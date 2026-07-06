<?php

namespace App\Filament\Resources\Procurement\Platform\Platforms;

use App\Filament\Resources\Procurement\Platform\Platforms\Pages\CreatePlatform;
use App\Filament\Resources\Procurement\Platform\Platforms\Pages\EditPlatform;
use App\Filament\Resources\Procurement\Platform\Platforms\Pages\ListPlatforms;
use App\Filament\Resources\Procurement\Platform\Platforms\Schemas\PlatformForm;
use App\Filament\Resources\Procurement\Platform\Platforms\Tables\PlatformsTable;
use App\Models\Procurement\Platform\Platform;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PlatformResource extends Resource
{
    protected static ?string $model = Platform::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Краулинг';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return PlatformForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlatformsTable::configure($table);
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
            'index' => ListPlatforms::route('/'),
            'create' => CreatePlatform::route('/create'),
            'edit' => EditPlatform::route('/{record}/edit'),
        ];
    }
}
