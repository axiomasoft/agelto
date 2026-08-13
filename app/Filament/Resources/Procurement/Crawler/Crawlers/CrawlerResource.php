<?php

namespace App\Filament\Resources\Procurement\Crawler\Crawlers;

use App\Filament\Resources\Procurement\Crawler\Crawlers\Pages\CreateCrawler;
use App\Filament\Resources\Procurement\Crawler\Crawlers\Pages\EditCrawler;
use App\Filament\Resources\Procurement\Crawler\Crawlers\Pages\ListCrawlers;
use App\Filament\Resources\Procurement\Crawler\Crawlers\Schemas\CrawlerForm;
use App\Filament\Resources\Procurement\Crawler\Crawlers\Tables\CrawlersTable;
use App\Models\Procurement\Crawler\Crawler;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CrawlerResource extends Resource
{
    protected static ?string $model = Crawler::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Сбор данных';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Задания сбора';

    protected static ?string $modelLabel = 'задание сбора';

    protected static ?string $pluralModelLabel = 'задания сбора';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return CrawlerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CrawlersTable::configure($table);
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
            'index' => ListCrawlers::route('/'),
            'create' => CreateCrawler::route('/create'),
            'edit' => EditCrawler::route('/{record}/edit'),
        ];
    }
}
