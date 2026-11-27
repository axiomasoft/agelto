<?php

declare(strict_types=1);

namespace App\Filament\Resources\Procurement\Ai\Fixtures;

use App\Filament\Resources\Procurement\Ai\Fixtures\Pages\CreateFixture;
use App\Filament\Resources\Procurement\Ai\Fixtures\Pages\EditFixture;
use App\Filament\Resources\Procurement\Ai\Fixtures\Pages\ListFixtures;
use App\Filament\Resources\Procurement\Ai\Fixtures\Pages\ViewFixture;
use App\Filament\Resources\Procurement\Ai\Fixtures\Pages\ViewFixtureGold;
use App\Filament\Resources\Procurement\Ai\Fixtures\RelationManagers\RunsRelationManager;
use App\Filament\Resources\Procurement\Ai\Fixtures\Schemas\FixtureForm;
use App\Filament\Resources\Procurement\Ai\Fixtures\Schemas\FixtureInfolist;
use App\Filament\Resources\Procurement\Ai\Fixtures\Tables\FixturesTable;
use App\Models\Procurement\Ai\Fixture;
use AzGuard\Filament\Concerns\AuthorizesResource;
use BackedEnum;
use Filament\Navigation\NavigationItem;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Реестр эталонных документов обучающего контура: загрузка файла,
 * обе оси типа, `type_profile`, валидация профиля анализатором типа через `instanceof
 * SupportsTypeProfileValidation` — экран сам полей профиля не знает.
 */
class FixtureResource extends Resource
{
    use AuthorizesResource;

    protected static ?string $azguardKey = 'fixture';

    protected static ?string $model = Fixture::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Качество анализа';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Эталонные документы';

    protected static ?string $modelLabel = 'эталонный документ';

    protected static ?string $pluralModelLabel = 'эталонные документы';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return FixtureForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return FixtureInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FixturesTable::configure($table);
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    protected static function modifyEloquentQuery(Builder $query): Builder
    {
        return $query->with(['document', 'datasetExamples']);
    }

    public static function getRelations(): array
    {
        return [
            RunsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFixtures::route('/'),
            'create' => CreateFixture::route('/create'),
            'view' => ViewFixture::route('/{record}'),
            'gold' => ViewFixtureGold::route('/{record}/gold'),
            'edit' => EditFixture::route('/{record}/edit'),
        ];
    }

    /** @return array<NavigationItem> */
    public static function getRecordSubNavigation(Page $page): array
    {
        assert($page instanceof ViewRecord);

        return [
            NavigationItem::make('Эталон')
                ->url(static::getUrl('view', ['record' => $page->getRecord()])),
            NavigationItem::make('Золото')
                ->url(static::getUrl('gold', ['record' => $page->getRecord()])),
        ];
    }
}
