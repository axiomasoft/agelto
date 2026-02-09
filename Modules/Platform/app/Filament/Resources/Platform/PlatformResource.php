<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\Platform;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Platform\Filament\Resources\Platform\Schemas\PlatformForm;
use Platform\Filament\Resources\Platform\Tables\PlatformsTable;
use Platform\Models\Platform;

final class PlatformResource extends Resource
{
    protected static ?string $model = Platform::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Площадки';

    protected static ?string $breadcrumb = 'Площадки';

    protected static ?string $modelLabel = 'Площадка';

    protected static ?string $pluralLabel = 'Площадки';

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
            'index' => Pages\ListPlatforms::route('/'),
            'create' => Pages\CreatePlatform::route('/create'),
            'edit' => Pages\EditPlatform::route('/{record}/edit'),
        ];
    }
}
