<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\Promt;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Platform\Filament\Resources\Promt\Schemas\PromtForm;
use Platform\Filament\Resources\Promt\Tables\PromtsTable;
use Platform\Models\Promt\PromptRule;

final class PromtResource extends Resource
{
    protected static ?string $model = PromptRule::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Правила промптов';

    protected static ?string $breadcrumb = 'Правила промптов';

    protected static ?string $modelLabel = 'Правило промпта';

    protected static ?string $pluralLabel = 'Правила промптов';

    public static function form(Schema $schema): Schema
    {
        return PromtForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PromtsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPromts::route('/'),
            'create' => Pages\CreatePromt::route('/create'),
            'edit' => Pages\EditPromt::route('/{record}/edit'),
        ];
    }
}
