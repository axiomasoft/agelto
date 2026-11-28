<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Platform\ParsingRuleSets;

use App\Filament\Resources\Procurement\Platform\ParsingRuleSets\Pages\CreateParsingRuleSet;
use App\Filament\Resources\Procurement\Platform\ParsingRuleSets\Pages\ListParsingRuleSets;
use App\Filament\Resources\Procurement\Platform\ParsingRuleSets\Pages\ViewParsingRuleSet;
use App\Filament\Resources\Procurement\Platform\ParsingRuleSets\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Procurement\Platform\ParsingRuleSets\RelationManagers\VersionsRelationManager;
use App\Filament\Resources\Procurement\Platform\ParsingRuleSets\Schemas\ParsingRuleSetForm;
use App\Filament\Resources\Procurement\Platform\ParsingRuleSets\Schemas\ParsingRuleSetInfolist;
use App\Filament\Resources\Procurement\Platform\ParsingRuleSets\Tables\ParsingRuleSetsTable;
use App\Guards\Admin\Permissions\ParsingRuleSetPermission;
use App\Models\Procurement\Parsing\ParsingRuleSet;
use AzGuard\Filament\Concerns\AuthorizesResource;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ParsingRuleSetResource extends Resource
{
    use AuthorizesResource;

    protected static ?string $azguardKey = 'parsing_rule_set';

    protected static ?string $model = ParsingRuleSet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|\UnitEnum|null $navigationGroup = 'Площадки';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Правила парсинга';

    protected static ?string $modelLabel = 'набор правил парсинга';

    protected static ?string $pluralModelLabel = 'правила парсинга';

    public static function form(Schema $schema): Schema
    {
        return ParsingRuleSetForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ParsingRuleSetInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ParsingRuleSetsTable::configure($table);
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected static function modifyEloquentQuery(Builder $query): Builder
    {
        return $query->with(['platform', 'activeVersion']);
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->hasPermission(ParsingRuleSetPermission::ViewAny) ?? false;
    }

    public static function canView(Model $record): bool
    {
        return Auth::user()?->hasPermission(ParsingRuleSetPermission::View) ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasPermission(ParsingRuleSetPermission::Create) ?? false;
    }

    public static function getRelations(): array
    {
        return [
            VersionsRelationManager::class,
            EventsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListParsingRuleSets::route('/'),
            'create' => CreateParsingRuleSet::route('/create'),
            'view' => ViewParsingRuleSet::route('/{record}'),
        ];
    }
}
