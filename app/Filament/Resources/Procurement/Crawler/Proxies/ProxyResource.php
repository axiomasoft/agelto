<?php

namespace App\Filament\Resources\Procurement\Crawler\Proxies;

use App\Filament\Resources\Procurement\Crawler\Proxies\Pages\CreateProxy;
use App\Filament\Resources\Procurement\Crawler\Proxies\Pages\EditProxy;
use App\Filament\Resources\Procurement\Crawler\Proxies\Pages\ListProxies;
use App\Filament\Resources\Procurement\Crawler\Proxies\Schemas\ProxyForm;
use App\Filament\Resources\Procurement\Crawler\Proxies\Tables\ProxiesTable;
use App\Models\Procurement\Crawler\Proxy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProxyResource extends Resource
{
    protected static ?string $model = Proxy::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Краулинг';

    protected static ?int $navigationSort = 40;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return ProxyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProxiesTable::configure($table);
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
            'index' => ListProxies::route('/'),
            'create' => CreateProxy::route('/create'),
            'edit' => EditProxy::route('/{record}/edit'),
        ];
    }
}
