<?php

namespace App\Filament\Resources\Procurement\Crawler\IgnoreRules;

use App\Filament\Resources\Procurement\Crawler\IgnoreRules\Pages\CreateIgnoreRule;
use App\Filament\Resources\Procurement\Crawler\IgnoreRules\Pages\EditIgnoreRule;
use App\Filament\Resources\Procurement\Crawler\IgnoreRules\Pages\ListIgnoreRules;
use App\Filament\Resources\Procurement\Crawler\IgnoreRules\Schemas\IgnoreRuleForm;
use App\Filament\Resources\Procurement\Crawler\IgnoreRules\Tables\IgnoreRulesTable;
use App\Models\Procurement\Crawler\IgnoreRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class IgnoreRuleResource extends Resource
{
    protected static ?string $model = IgnoreRule::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Краулинг';

    protected static ?int $navigationSort = 50;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return IgnoreRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return IgnoreRulesTable::configure($table);
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
            'index' => ListIgnoreRules::route('/'),
            'create' => CreateIgnoreRule::route('/create'),
            'edit' => EditIgnoreRule::route('/{record}/edit'),
        ];
    }
}
