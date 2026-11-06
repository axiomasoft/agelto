<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\LlmRoutes;

use App\Filament\Resources\Procurement\Ai\LlmRoutes\Pages\CreateAiRoute;
use App\Filament\Resources\Procurement\Ai\LlmRoutes\Pages\EditAiRoute;
use App\Filament\Resources\Procurement\Ai\LlmRoutes\Pages\ListAiRoutes;
use App\Filament\Resources\Procurement\Ai\LlmRoutes\Schemas\AiRouteForm;
use App\Filament\Resources\Procurement\Ai\LlmRoutes\Tables\AiRoutesTable;
use App\Models\Llm\LlmRoute;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class LlmRouteResource extends Resource
{
    protected static ?string $model = LlmRoute::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Настройка AI';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Модели для задач';

    protected static ?string $modelLabel = 'модель для задачи';

    protected static ?string $pluralModelLabel = 'модели для задач';

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
