<?php

namespace App\Filament\Resources\Procurement\Ai\AiRoutes;

use App\Filament\Resources\Procurement\Ai\AiRoutes\Pages\CreateAiRoute;
use App\Filament\Resources\Procurement\Ai\AiRoutes\Pages\EditAiRoute;
use App\Filament\Resources\Procurement\Ai\AiRoutes\Pages\ListAiRoutes;
use App\Filament\Resources\Procurement\Ai\AiRoutes\Schemas\AiRouteForm;
use App\Filament\Resources\Procurement\Ai\AiRoutes\Tables\AiRoutesTable;
use App\Models\Procurement\Ai\AiRoute;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AiRouteResource extends Resource
{
    protected static ?string $model = AiRoute::class;

    protected static string|\UnitEnum|null $navigationGroup = 'AI-анализ';

    protected static ?int $navigationSort = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return AiRouteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AiRoutesTable::configure($table);
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
            'index' => ListAiRoutes::route('/'),
            'create' => CreateAiRoute::route('/create'),
            'edit' => EditAiRoute::route('/{record}/edit'),
        ];
    }
}
