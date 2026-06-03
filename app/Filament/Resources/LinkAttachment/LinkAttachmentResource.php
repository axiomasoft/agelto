<?php

declare(strict_types=1);

namespace App\Filament\Resources\LinkAttachment;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use App\Filament\Resources\LinkAttachment\Schemas\LinkAttachmentForm;
use App\Filament\Resources\LinkAttachment\Tables\LinkAttachmentsTable;
use App\Models\Crawler\LinkAttachment;

final class LinkAttachmentResource extends Resource
{
    protected static ?string $model = LinkAttachment::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Вложения для обработки';

    protected static ?string $breadcrumb = 'Вложения';

    protected static ?string $modelLabel = 'Вложение';

    protected static ?string $pluralLabel = 'Вложения';

    public static function form(Schema $schema): Schema
    {
        return LinkAttachmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LinkAttachmentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLinkAttachments::route('/'),
            'create' => Pages\CreateLinkAttachment::route('/create'),
            'edit' => Pages\EditLinkAttachment::route('/{record}/edit'),
        ];
    }
}
