<?php

declare(strict_types=1);

namespace Sys\Filament\Resources\Service;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Sys\Filament\Resources\Service\Schemas\ServiceForm;
use Sys\Filament\Resources\Service\Tables\ServicesTable;
use Sys\Models\Proxy\Service;

final class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Сервисы прокси';

    protected static ?string $breadcrumb = 'Сервисы прокси';

    protected static ?string $modelLabel = 'Сервис прокси';

    protected static ?string $pluralLabel = 'Сервисы прокси';

    public static function form(Schema $schema): Schema
    {
        return ServiceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServicesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServices::route('/'),
            'create' => Pages\CreateService::route('/create'),
            'edit' => Pages\EditService::route('/{record}/edit'),
        ];
    }
}
