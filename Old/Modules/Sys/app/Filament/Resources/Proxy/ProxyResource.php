<?php

declare(strict_types=1);

namespace Sys\Filament\Resources\Proxy;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Sys\Filament\Resources\Proxy\Schemas\ProxyForm;
use Sys\Filament\Resources\Proxy\Tables\ProxiesTable;
use Sys\Models\Proxy\Proxy;

final class ProxyResource extends Resource
{
    protected static ?string $model = Proxy::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Прокси';

    protected static ?string $breadcrumb = 'Прокси';

    protected static ?string $modelLabel = 'Прокси';

    protected static ?string $pluralLabel = 'Прокси';

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
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProxies::route('/'),
            'create' => Pages\CreateProxy::route('/create'),
            'edit' => Pages\EditProxy::route('/{record}/edit'),
        ];
    }
}
