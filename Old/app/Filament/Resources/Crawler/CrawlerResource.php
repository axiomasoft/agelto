<?php

declare(strict_types=1);

namespace App\Filament\Resources\Crawler;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use App\Filament\Resources\Crawler\Schemas\CrawlerForm;
use App\Filament\Resources\Crawler\Tables\CrawlersTable;
use App\Models\Crawler\Crawler;

final class CrawlerResource extends Resource
{
    protected static ?string $model = Crawler::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Профили сборщиков данных';

    protected static ?string $breadcrumb = 'Элементы';

    protected static ?string $modelLabel = 'Элемент';

    protected static ?string $pluralLabel = 'Элементы';

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
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCrawlers::route('/'),
            'create' => Pages\CreateCrawler::route('/create'),
            'edit' => Pages\EditCrawler::route('/{record}/edit'),
        ];
    }
}
