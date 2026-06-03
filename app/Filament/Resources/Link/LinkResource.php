<?php

declare(strict_types=1);

namespace App\Filament\Resources\Link;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use App\Filament\Resources\Link\Schemas\LinkForm;
use App\Filament\Resources\Link\Tables\LinksTable;
use App\Models\Crawler\Link;

final class LinkResource extends Resource
{
    protected static ?string $model = Link::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Ссылки для обработки';

    protected static ?string $breadcrumb = 'Ссылки';

    protected static ?string $modelLabel = 'Ссылка';

    protected static ?string $pluralLabel = 'Ссылки';

    public static function form(Schema $schema): Schema
    {
        return LinkForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LinksTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\AttachmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLinks::route('/'),
            'create' => Pages\CreateLink::route('/create'),
            'edit' => Pages\EditLink::route('/{record}/edit'),
        ];
    }
}
