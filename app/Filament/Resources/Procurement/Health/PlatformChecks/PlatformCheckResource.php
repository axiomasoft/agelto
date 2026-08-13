<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Health\PlatformChecks;

use App\Filament\Resources\Procurement\Health\PlatformChecks\Pages\ListPlatformChecks;
use App\Filament\Resources\Procurement\Health\PlatformChecks\Pages\ViewPlatformCheck;
use App\Filament\Resources\Procurement\Health\PlatformChecks\Schemas\PlatformCheckInfolist;
use App\Filament\Resources\Procurement\Health\PlatformChecks\Tables\PlatformChecksTable;
use App\Models\Procurement\Health\PlatformCheck;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PlatformCheckResource extends Resource
{
    protected static ?string $model = PlatformCheck::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    protected static string|\UnitEnum|null $navigationGroup = 'Площадки';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Проверки площадок';

    protected static ?string $modelLabel = 'проверка площадки';

    protected static ?string $pluralModelLabel = 'проверки площадок';

    public static function infolist(Schema $schema): Schema
    {
        return PlatformCheckInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlatformChecksTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('platform');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlatformChecks::route('/'),
            'view' => ViewPlatformCheck::route('/{record}'),
        ];
    }
}
