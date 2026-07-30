<?php

namespace App\Filament\Resources\Procurement\Ai\Fixtures;

use App\Filament\Resources\Procurement\Ai\Fixtures\Pages\CreateFixture;
use App\Filament\Resources\Procurement\Ai\Fixtures\Pages\EditFixture;
use App\Filament\Resources\Procurement\Ai\Fixtures\Pages\ListFixtures;
use App\Filament\Resources\Procurement\Ai\Fixtures\Pages\ViewFixture;
use App\Filament\Resources\Procurement\Ai\Fixtures\Schemas\FixtureForm;
use App\Filament\Resources\Procurement\Ai\Fixtures\Schemas\FixtureInfolist;
use App\Filament\Resources\Procurement\Ai\Fixtures\Tables\FixturesTable;
use App\Models\Procurement\Ai\Fixture;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Реестр эталонных документов обучающего контура (P5.1, D6/D18/D90/D132/D133): загрузка файла,
 * обе оси типа, `type_profile`, валидация профиля анализатором типа через `instanceof
 * SupportsTypeProfileValidation` — экран сам полей профиля не знает (D43).
 */
class FixtureResource extends Resource
{
    protected static ?string $model = Fixture::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'AI-анализ';

    protected static ?int $navigationSort = 50;

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

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFixtures::route('/'),
            'create' => CreateFixture::route('/create'),
            'view' => ViewFixture::route('/{record}'),
            'edit' => EditFixture::route('/{record}/edit'),
        ];
    }
}
