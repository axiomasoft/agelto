<?php

namespace App\Filament\Resources\Procurement\Ai\Providers;

use App\Filament\Resources\Procurement\Ai\Providers\Pages\CreateProvider;
use App\Filament\Resources\Procurement\Ai\Providers\Pages\EditProvider;
use App\Filament\Resources\Procurement\Ai\Providers\Pages\ListProviders;
use App\Filament\Resources\Procurement\Ai\Providers\Schemas\ProviderForm;
use App\Filament\Resources\Procurement\Ai\Providers\Tables\ProvidersTable;
use App\Models\Llm\Provider;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ProviderResource extends Resource
{
    protected static ?string $model = Provider::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Настройка AI';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'AI-провайдеры';

    protected static ?string $modelLabel = 'AI-провайдер';

    protected static ?string $pluralModelLabel = 'AI-провайдеры';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return ProviderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProvidersTable::configure($table);
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
            'index' => ListProviders::route('/'),
            'create' => CreateProvider::route('/create'),
            'edit' => EditProvider::route('/{record}/edit'),
        ];
    }
}
