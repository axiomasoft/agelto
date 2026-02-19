<?php

declare(strict_types=1);

namespace Platform\Filament\Resources\PromptRule;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Platform\Filament\Resources\PromptRule\Schemas\PromptRuleForm;
use Platform\Filament\Resources\PromptRule\Tables\PromptRulesTable;
use Platform\Models\Promt\PromptRule;

final class PromptRuleResource extends Resource
{
    protected static ?string $model = PromptRule::class;

    protected static string|BackedEnum|null $navigationIcon = null;

    protected static ?string $navigationLabel = 'Правила промптов';

    protected static ?string $breadcrumb = 'Правила промптов';

    protected static ?string $modelLabel = 'Правило промпта';

    protected static ?string $pluralLabel = 'Правила промптов';

    public static function form(Schema $schema): Schema
    {
        return PromptRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PromptRulesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPromptRules::route('/'),
            'create' => Pages\CreatePromptRule::route('/create'),
            'edit' => Pages\EditPromptRule::route('/{record}/edit'),
        ];
    }
}
